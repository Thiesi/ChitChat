<?php

declare(strict_types=1);

namespace ChitChat\Moderation;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class ReportService
{
    private const CATEGORIES = [
        'spam',
        'harassment',
        'hate',
        'threats',
        'sexual_content',
        'privacy',
        'impersonation',
        'other',
    ];

    private const RESOLUTION_CODES = [
        'no_violation',
        'content_removed',
        'user_warned',
        'account_restricted',
        'other',
    ];

    private readonly AuditLogger $audit;
    private readonly RoomRepository $rooms;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
        $this->rooms = new RoomRepository($pdo);
    }

    /** @return array{id:int, status:string, report_count:int} */
    public function reportRoomMessage(
        AuthenticatedUser $actor,
        int $messageId,
        string $categoryInput,
        ?string $detailsInput,
        string $ipAddress,
    ): array {
        $message = $this->roomMessage($messageId);
        $room = $this->rooms->findForUser((int) $message['room_id'], $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }
        RoomAuthorization::requireHistory($actor, $room);
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);
        $this->requireReportable($actor, $message, 'room');

        return $this->createReport(
            actor: $actor,
            messageKind: 'room',
            messageId: $messageId,
            roomId: (int) $message['room_id'],
            subjectUserId: (int) $message['subject_user_id'],
            category: $this->category($categoryInput),
            details: $this->boundedText($detailsInput),
            evidenceBody: $message['body'] === null ? null : (string) $message['body'],
            evidence: [
                'message_type' => (string) $message['message_type'],
                'created_at' => (string) $message['created_at'],
                'edited_at' => $message['edited_at'] === null ? null : (string) $message['edited_at'],
                'room_id' => (int) $message['room_id'],
                'room_name' => (string) $message['room_name'],
                'attachment' => $this->attachmentEvidence($message),
            ],
            ipAddress: $ipAddress,
        );
    }

    /** @return array{id:int, status:string, report_count:int} */
    public function reportDirectMessage(
        AuthenticatedUser $actor,
        int $messageId,
        string $categoryInput,
        ?string $detailsInput,
        string $ipAddress,
    ): array {
        $message = $this->directMessage($actor, $messageId);
        $this->requireReportable($actor, $message, 'direct');
        if ((int) $message['recipient_user_id'] !== $actor->id) {
            throw new ApiException(403, 'report_recipient_required', 'Only the recipient may report this direct message.');
        }

        return $this->createReport(
            actor: $actor,
            messageKind: 'direct',
            messageId: $messageId,
            roomId: null,
            subjectUserId: (int) $message['subject_user_id'],
            category: $this->category($categoryInput),
            details: $this->boundedText($detailsInput),
            evidenceBody: $message['body'] === null ? null : (string) $message['body'],
            evidence: [
                'created_at' => (string) $message['created_at'],
                'edited_at' => $message['edited_at'] === null ? null : (string) $message['edited_at'],
                'attachment' => $this->attachmentEvidence($message),
            ],
            ipAddress: $ipAddress,
        );
    }

    /**
     * @return array{
     *   cases:list<array<string, mixed>>,
     *   has_more:bool,
     *   next_before_id:?int
     * }
     */
    public function cases(
        AuthenticatedUser $actor,
        string $status = 'open',
        ?int $beforeId = null,
        int $limit = 50,
    ): array {
        $this->requireQueueAccess($actor);
        if (!in_array($status, ['open', 'in_review', 'resolved', 'dismissed', 'all'], true)) {
            throw new ApiException(400, 'validation_error', 'status is invalid.');
        }
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiException(400, 'validation_error', 'before_id must be positive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $sql = <<<'SQL'
SELECT c.id,
       c.message_kind,
       c.message_id,
       c.room_id,
       room.name AS room_name,
       c.subject_user_id,
       subject.username AS subject_username,
       c.status,
       c.assigned_user_id,
       assigned.username AS assigned_username,
       c.resolution_code,
       c.first_reported_at,
       c.last_reported_at,
       c.resolved_at,
       (SELECT COUNT(*) FROM moderation_reports report WHERE report.case_id = c.id)::integer AS report_count
FROM moderation_cases c
JOIN users subject ON subject.id = c.subject_user_id
LEFT JOIN users assigned ON assigned.id = c.assigned_user_id
LEFT JOIN rooms room ON room.id = c.room_id
WHERE (
    CAST(:review_all AS integer) = 1
    OR (
        c.message_kind = 'room'
        AND EXISTS (
            SELECT 1
            FROM room_members membership
            WHERE membership.room_id = c.room_id
              AND membership.user_id = :actor_id
              AND membership.role IN ('owner', 'moderator')
        )
    )
)
SQL;
        if ($status !== 'all') {
            $sql .= "\n  AND c.status = :status";
        }
        if ($beforeId !== null) {
            $sql .= "\n  AND c.id < :before_id";
        }
        $sql .= "\nORDER BY c.id DESC\nLIMIT :limit";

        $statement = $this->prepare($sql, 'moderation queue');
        $statement->bindValue(':review_all', $this->canReviewAll($actor) ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue(':actor_id', $actor->id, PDO::PARAM_INT);
        if ($status !== 'all') {
            $statement->bindValue(':status', $status);
        }
        if ($beforeId !== null) {
            $statement->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $cases = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $cases[] = $this->caseSummary($row);
            }
        }

        return [
            'cases' => $cases,
            'has_more' => $hasMore,
            'next_before_id' => $hasMore && $cases !== []
                ? (int) $cases[array_key_last($cases)]['id']
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function caseDetail(AuthenticatedUser $actor, int $caseId): array
    {
        $case = $this->caseRow($caseId, false);
        $this->requireCaseAccess($actor, $case);

        $statement = $this->prepare(<<<'SQL'
SELECT report.id,
       report.reporter_user_id,
       reporter.username AS reporter_username,
       report.category,
       report.details,
       report.evidence_body,
       report.evidence_json,
       report.created_at
FROM moderation_reports report
JOIN users reporter ON reporter.id = report.reporter_user_id
WHERE report.case_id = :case_id
ORDER BY report.id
SQL, 'moderation report detail');
        $statement->execute(['case_id' => $caseId]);

        $reports = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reports[] = [
                'id' => (int) $row['id'],
                'reporter' => [
                    'id' => (int) $row['reporter_user_id'],
                    'username' => (string) $row['reporter_username'],
                ],
                'category' => (string) $row['category'],
                'details' => $row['details'] === null ? null : (string) $row['details'],
                'evidence_body' => $row['evidence_body'] === null ? null : (string) $row['evidence_body'],
                'evidence' => $this->decodeEvidence((string) $row['evidence_json']),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return [
            ...$this->caseSummary($case),
            'resolution_note' => $case['resolution_note'] === null ? null : (string) $case['resolution_note'],
            'resolved_by' => $case['resolved_by_user_id'] === null ? null : [
                'id' => (int) $case['resolved_by_user_id'],
                'username' => (string) $case['resolved_by_username'],
            ],
            'reports' => $reports,
        ];
    }

    /** @return array<string, mixed> */
    public function claim(AuthenticatedUser $actor, int $caseId, bool $claim, string $ipAddress): array
    {
        $this->pdo->beginTransaction();
        try {
            $case = $this->caseRow($caseId, true);
            $this->requireCaseAccess($actor, $case);
            if (in_array((string) $case['status'], ['resolved', 'dismissed'], true)) {
                throw new ApiException(409, 'moderation_case_closed', 'Closed cases cannot be assigned.');
            }

            if ($claim) {
                if ($case['assigned_user_id'] !== null && (int) $case['assigned_user_id'] !== $actor->id) {
                    throw new ApiException(409, 'moderation_case_assigned', 'This case is already assigned to another moderator.');
                }
                $assignedUserId = $actor->id;
                $status = 'in_review';
                $action = 'moderation.case_claimed';
            } else {
                if (
                    $case['assigned_user_id'] !== null
                    && (int) $case['assigned_user_id'] !== $actor->id
                    && !$this->canReviewAll($actor)
                ) {
                    throw new ApiException(403, 'forbidden', 'Only the assigned moderator may release this case.');
                }
                $assignedUserId = null;
                $status = 'open';
                $action = 'moderation.case_released';
            }

            $statement = $this->prepare(<<<'SQL'
UPDATE moderation_cases
SET assigned_user_id = :assigned_user_id,
    status = :status,
    updated_at = NOW()
WHERE id = :case_id
SQL, 'moderation assignment update');
            $statement->bindValue(
                ':assigned_user_id',
                $assignedUserId,
                $assignedUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
            );
            $statement->bindValue(':status', $status);
            $statement->bindValue(':case_id', $caseId, PDO::PARAM_INT);
            $statement->execute();

            $this->audit->log(
                $actor->id,
                $action,
                'moderation_case',
                (string) $caseId,
                $this->auditMetadata($case),
                $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->caseDetail($actor, $caseId);
    }

    /** @return array<string, mixed> */
    public function resolve(
        AuthenticatedUser $actor,
        int $caseId,
        string $status,
        string $resolutionCode,
        ?string $noteInput,
        string $ipAddress,
    ): array {
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            throw new ApiException(400, 'validation_error', 'status must be resolved or dismissed.');
        }
        if (!in_array($resolutionCode, self::RESOLUTION_CODES, true)) {
            throw new ApiException(400, 'validation_error', 'resolution_code is invalid.');
        }
        if ($status === 'dismissed' && $resolutionCode !== 'no_violation') {
            throw new ApiException(400, 'validation_error', 'Dismissed cases must use no_violation.');
        }
        if ($status === 'resolved' && $resolutionCode === 'no_violation') {
            throw new ApiException(400, 'validation_error', 'Resolved cases must record an action outcome.');
        }
        $note = $this->boundedText($noteInput);
        if ($resolutionCode === 'other' && $note === null) {
            throw new ApiException(400, 'validation_error', 'A note is required for the other outcome.');
        }

        $this->pdo->beginTransaction();
        try {
            $case = $this->caseRow($caseId, true);
            $this->requireCaseAccess($actor, $case);
            if (in_array((string) $case['status'], ['resolved', 'dismissed'], true)) {
                throw new ApiException(409, 'moderation_case_closed', 'This moderation case is already closed.');
            }

            $statement = $this->prepare(<<<'SQL'
UPDATE moderation_cases
SET status = :status,
    assigned_user_id = COALESCE(assigned_user_id, :actor_id),
    resolved_by_user_id = :actor_id,
    resolution_code = :resolution_code,
    resolution_note = :resolution_note,
    resolved_at = NOW(),
    updated_at = NOW()
WHERE id = :case_id
SQL, 'moderation resolution');
            $statement->bindValue(':status', $status);
            $statement->bindValue(':actor_id', $actor->id, PDO::PARAM_INT);
            $statement->bindValue(':resolution_code', $resolutionCode);
            $statement->bindValue(
                ':resolution_note',
                $note,
                $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
            );
            $statement->bindValue(':case_id', $caseId, PDO::PARAM_INT);
            $statement->execute();

            $this->audit->log(
                $actor->id,
                'moderation.case_closed',
                'moderation_case',
                (string) $caseId,
                [
                    ...$this->auditMetadata($case),
                    'status' => $status,
                    'resolution_code' => $resolutionCode,
                ],
                $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return $this->caseDetail($actor, $caseId);
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array{id:int, status:string, report_count:int}
     * @throws JsonException
     */
    private function createReport(
        AuthenticatedUser $actor,
        string $messageKind,
        int $messageId,
        ?int $roomId,
        int $subjectUserId,
        string $category,
        ?string $details,
        ?string $evidenceBody,
        array $evidence,
        string $ipAddress,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->prepare(
                'SELECT pg_advisory_xact_lock(hashtext(:case_key))',
                'moderation-case lock',
            );
            $lock->execute(['case_key' => 'moderation:' . $messageKind . ':' . $messageId]);

            $caseStatement = $this->prepare(<<<'SQL'
INSERT INTO moderation_cases (
    message_kind,
    message_id,
    room_id,
    subject_user_id
)
VALUES (
    :message_kind,
    :message_id,
    :room_id,
    :subject_user_id
)
ON CONFLICT (message_kind, message_id)
DO UPDATE SET
    status = CASE
        WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN 'open'
        ELSE moderation_cases.status
    END,
    assigned_user_id = CASE
        WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL
        ELSE moderation_cases.assigned_user_id
    END,
    resolved_by_user_id = CASE
        WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL
        ELSE moderation_cases.resolved_by_user_id
    END,
    resolution_code = CASE
        WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL
        ELSE moderation_cases.resolution_code
    END,
    resolution_note = CASE
        WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL
        ELSE moderation_cases.resolution_note
    END,
    resolved_at = CASE
        WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL
        ELSE moderation_cases.resolved_at
    END,
    last_reported_at = NOW(),
    updated_at = NOW()
RETURNING id, status
SQL, 'moderation case creation');
            $caseStatement->bindValue(':message_kind', $messageKind);
            $caseStatement->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $caseStatement->bindValue(
                ':room_id',
                $roomId,
                $roomId === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
            );
            $caseStatement->bindValue(':subject_user_id', $subjectUserId, PDO::PARAM_INT);
            $caseStatement->execute();
            $case = $caseStatement->fetch();
            if (!is_array($case)) {
                throw new RuntimeException('Moderation case creation did not return a row.');
            }
            $caseId = (int) $case['id'];

            $reportStatement = $this->prepare(<<<'SQL'
INSERT INTO moderation_reports (
    case_id,
    reporter_user_id,
    category,
    details,
    evidence_body,
    evidence_json
)
VALUES (
    :case_id,
    :reporter_user_id,
    :category,
    :details,
    :evidence_body,
    CAST(:evidence_json AS jsonb)
)
ON CONFLICT (case_id, reporter_user_id) DO NOTHING
RETURNING id
SQL, 'participant report creation');
            $reportStatement->bindValue(':case_id', $caseId, PDO::PARAM_INT);
            $reportStatement->bindValue(':reporter_user_id', $actor->id, PDO::PARAM_INT);
            $reportStatement->bindValue(':category', $category);
            $reportStatement->bindValue(
                ':details',
                $details,
                $details === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
            );
            $reportStatement->bindValue(
                ':evidence_body',
                $evidenceBody,
                $evidenceBody === null ? PDO::PARAM_NULL : PDO::PARAM_STR,
            );
            $reportStatement->bindValue(':evidence_json', json_encode($evidence, JSON_THROW_ON_ERROR));
            $reportStatement->execute();
            $reportId = $reportStatement->fetchColumn();
            if ($reportId === false) {
                throw new ApiException(409, 'message_already_reported', 'You have already reported this message.');
            }

            $this->audit->log(
                $actor->id,
                'moderation.report_created',
                'moderation_case',
                (string) $caseId,
                [
                    'case_id' => $caseId,
                    'report_id' => (int) $reportId,
                    'message_kind' => $messageKind,
                    'message_id' => $messageId,
                    'room_id' => $roomId,
                    'subject_user_id' => $subjectUserId,
                    'category' => $category,
                ],
                $ipAddress,
            );
            $count = $this->prepare(
                'SELECT COUNT(*) FROM moderation_reports WHERE case_id = :case_id',
                'moderation report count',
            );
            $count->execute(['case_id' => $caseId]);
            $reportCount = (int) $count->fetchColumn();
            $this->pdo->commit();

            return [
                'id' => $caseId,
                'status' => (string) $case['status'],
                'report_count' => $reportCount,
            ];
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function roomMessage(int $messageId): array
    {
        $this->requirePositiveId($messageId, 'message_id');
        $statement = $this->prepare(<<<'SQL'
SELECT message.id,
       message.room_id,
       message.sender_id AS subject_user_id,
       message.message_type,
       message.body,
       message.created_at,
       message.edited_at,
       message.deleted_at,
       room.name AS room_name,
       attachment.id AS attachment_id,
       attachment.original_name AS attachment_name,
       attachment.mime_type AS attachment_mime_type,
       attachment.size_bytes AS attachment_size_bytes
FROM room_messages message
JOIN rooms room ON room.id = message.room_id
LEFT JOIN attachments attachment ON attachment.message_id = message.id
WHERE message.id = :message_id
  AND room.deleted_at IS NULL
SQL, 'reportable room-message lookup');
        $statement->execute(['message_id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function directMessage(AuthenticatedUser $actor, int $messageId): array
    {
        $this->requirePositiveId($messageId, 'message_id');
        $statement = $this->prepare(<<<'SQL'
SELECT message.id,
       message.sender_user_id AS subject_user_id,
       message.sender_user_id,
       message.recipient_user_id,
       message.body,
       message.created_at,
       message.edited_at,
       message.deleted_at,
       attachment.id AS attachment_id,
       attachment.original_name AS attachment_name,
       attachment.mime_type AS attachment_mime_type,
       attachment.size_bytes AS attachment_size_bytes
FROM direct_messages message
LEFT JOIN direct_message_attachments attachment ON attachment.direct_message_id = message.id
WHERE message.id = :message_id
  AND (
      message.sender_user_id = :actor_sender
      OR message.recipient_user_id = :actor_recipient
  )
SQL, 'reportable direct-message lookup');
        $statement->execute([
            'message_id' => $messageId,
            'actor_sender' => $actor->id,
            'actor_recipient' => $actor->id,
        ]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Direct message not found.');
        }

        return $row;
    }

    /** @param array<string, mixed> $message */
    private function requireReportable(AuthenticatedUser $actor, array $message, string $kind): void
    {
        if ($message['deleted_at'] !== null) {
            throw new ApiException(409, 'message_not_reportable', 'Deleted messages cannot be reported through the participant interface.');
        }
        if ($message['subject_user_id'] === null) {
            throw new ApiException(409, 'message_not_reportable', 'System messages cannot be reported.');
        }
        if ((int) $message['subject_user_id'] === $actor->id) {
            throw new ApiException(409, 'message_not_reportable', 'You cannot report your own message.');
        }
        if ($kind === 'direct' && $message['body'] === null && $message['attachment_id'] === null) {
            throw new ApiException(409, 'message_not_reportable', 'This direct message has no reportable content.');
        }
    }

    private function category(string $category): string
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ApiException(400, 'validation_error', 'category is invalid.');
        }

        return $category;
    }

    private function boundedText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > 1000) {
            throw new ApiException(400, 'validation_error', 'Text must not exceed 1000 characters.');
        }

        return $value;
    }

    private function requireQueueAccess(AuthenticatedUser $actor): void
    {
        if ($this->canReviewAll($actor)) {
            return;
        }
        $statement = $this->prepare(<<<'SQL'
SELECT 1
FROM room_members
WHERE user_id = :user_id
  AND role IN ('owner', 'moderator')
LIMIT 1
SQL, 'moderation queue authorization');
        $statement->execute(['user_id' => $actor->id]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(403, 'forbidden', 'You do not have access to the moderation queue.');
        }
    }

    /** @param array<string, mixed> $case */
    private function requireCaseAccess(AuthenticatedUser $actor, array $case): void
    {
        if ($this->canReviewAll($actor)) {
            return;
        }
        if ((string) $case['message_kind'] !== 'room' || $case['room_id'] === null) {
            throw new ApiException(403, 'forbidden', 'You do not have access to this moderation case.');
        }
        $statement = $this->prepare(<<<'SQL'
SELECT 1
FROM room_members
WHERE room_id = :room_id
  AND user_id = :user_id
  AND role IN ('owner', 'moderator')
SQL, 'moderation case authorization');
        $statement->execute([
            'room_id' => (int) $case['room_id'],
            'user_id' => $actor->id,
        ]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException(403, 'forbidden', 'You do not have access to this moderation case.');
        }
    }

    private function canReviewAll(AuthenticatedUser $actor): bool
    {
        foreach (['super_admin', 'admin', 'chat_admin', 'global_moderator'] as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function caseRow(int $caseId, bool $forUpdate): array
    {
        $this->requirePositiveId($caseId, 'case_id');
        $locking = $forUpdate ? ' FOR UPDATE OF c' : '';
        $statement = $this->prepare(<<<SQL
SELECT c.id,
       c.message_kind,
       c.message_id,
       c.room_id,
       room.name AS room_name,
       c.subject_user_id,
       subject.username AS subject_username,
       c.status,
       c.assigned_user_id,
       assigned.username AS assigned_username,
       c.resolved_by_user_id,
       resolved_by.username AS resolved_by_username,
       c.resolution_code,
       c.resolution_note,
       c.first_reported_at,
       c.last_reported_at,
       c.resolved_at,
       (SELECT COUNT(*) FROM moderation_reports report WHERE report.case_id = c.id)::integer AS report_count
FROM moderation_cases c
JOIN users subject ON subject.id = c.subject_user_id
LEFT JOIN users assigned ON assigned.id = c.assigned_user_id
LEFT JOIN users resolved_by ON resolved_by.id = c.resolved_by_user_id
LEFT JOIN rooms room ON room.id = c.room_id
WHERE c.id = :case_id{$locking}
SQL, 'moderation-case lookup');
        $statement->execute(['case_id' => $caseId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'moderation_case_not_found', 'Moderation case not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function caseSummary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'message_kind' => (string) $row['message_kind'],
            'message_id' => (int) $row['message_id'],
            'room' => $row['room_id'] === null ? null : [
                'id' => (int) $row['room_id'],
                'name' => (string) $row['room_name'],
            ],
            'subject' => [
                'id' => (int) $row['subject_user_id'],
                'username' => (string) $row['subject_username'],
            ],
            'status' => (string) $row['status'],
            'assigned_to' => $row['assigned_user_id'] === null ? null : [
                'id' => (int) $row['assigned_user_id'],
                'username' => (string) $row['assigned_username'],
            ],
            'resolution_code' => $row['resolution_code'] === null ? null : (string) $row['resolution_code'],
            'report_count' => (int) $row['report_count'],
            'first_reported_at' => (string) $row['first_reported_at'],
            'last_reported_at' => (string) $row['last_reported_at'],
            'resolved_at' => $row['resolved_at'] === null ? null : (string) $row['resolved_at'],
        ];
    }

    /**
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    private function auditMetadata(array $case): array
    {
        return [
            'case_id' => (int) $case['id'],
            'message_kind' => (string) $case['message_kind'],
            'message_id' => (int) $case['message_id'],
            'room_id' => $case['room_id'] === null ? null : (int) $case['room_id'],
            'subject_user_id' => (int) $case['subject_user_id'],
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array{name:string, mime_type:string, size_bytes:int}|null
     */
    private function attachmentEvidence(array $message): ?array
    {
        if ($message['attachment_id'] === null) {
            return null;
        }

        return [
            'name' => (string) $message['attachment_name'],
            'mime_type' => (string) $message['attachment_mime_type'],
            'size_bytes' => (int) $message['attachment_size_bytes'],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeEvidence(string $encoded): array
    {
        $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function requirePositiveId(int $value, string $name): void
    {
        if ($value < 1) {
            throw new ApiException(400, 'validation_error', $name . ' must be positive.');
        }
    }

    private function prepare(string $sql, string $description): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare ' . $description . '.');
        }

        return $statement;
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
