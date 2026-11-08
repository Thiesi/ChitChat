<?php

declare(strict_types=1);

namespace ChitChat\Moderation;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Room\Room;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use ChitChat\Upload\AttachmentFileStore;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** Person reports never copy conversations or the original uploaded image. */
final class UserReportService
{
    public const MAX_SNAPSHOT_BYTES = 131072;
    private const CATEGORIES = ['offensive_username', 'offensive_profile_picture', 'spam', 'harassment',
        'hate', 'threats', 'sexual_content', 'privacy', 'impersonation', 'other'];

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /** @return array{can_report:bool, reported_by_me:bool} */
    public function profileStatus(AuthenticatedUser $actor, int $userId, ?int $roomId): array
    {
        try {
            $this->requireOther($actor, $userId);
            $this->subject($userId, false);
            $this->context($actor, $userId, $roomId);
        } catch (ApiException $exception) {
            if (!in_array($exception->status, [403, 404], true)) {
                throw $exception;
            }
            return ['can_report' => false, 'reported_by_me' => false];
        }
        $statement = $this->prepare(<<<'SQL'
SELECT 1 FROM moderation_cases c
JOIN moderation_reports report ON report.case_id = c.id
WHERE c.subject_kind = 'user' AND c.subject_user_id = :subject_id AND c.scope_key = :scope
  AND report.reporter_user_id = :reporter_id
SQL);
        $statement->execute(['subject_id' => $userId, 'scope' => self::scope($roomId), 'reporter_id' => $actor->id]);
        return ['can_report' => true, 'reported_by_me' => $statement->fetchColumn() !== false];
    }

    /** @return array{id:int, status:string, report_count:int} */
    public function report(
        AuthenticatedUser $actor,
        int $userId,
        string $category,
        ?string $details,
        ?int $roomId,
        string $ipAddress,
    ): array {
        $this->requireOther($actor, $userId);
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ApiException(400, 'validation_error', 'category is invalid.');
        }
        $details = $details === null ? null : trim($details);
        if ($details !== null && (!mb_check_encoding($details, 'UTF-8') || mb_strlen($details, 'UTF-8') > 1000)) {
            throw new ApiException(400, 'validation_error', 'Text must be valid UTF-8 and at most 1000 characters.');
        }
        $details = $details === '' ? null : $details;
        $this->pdo->beginTransaction();
        try {
            $room = $this->context($actor, $userId, $roomId);
            // Avatar replace/removal and account closure lock this row before unlinking.
            $subject = $this->subject($userId, true);
            if ($category === 'offensive_profile_picture' && $subject['avatar_key'] === null) {
                throw new ApiException(400, 'validation_error', 'This person has no profile picture.');
            }
            $statement = $this->prepare(<<<'SQL'
INSERT INTO moderation_cases (subject_kind, scope_key, subject_user_id, room_id)
VALUES ('user', :scope, :subject_id, :room_id)
ON CONFLICT (subject_user_id, scope_key) WHERE subject_kind = 'user'
DO UPDATE SET
    status = CASE WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN 'open' ELSE moderation_cases.status END,
    assigned_user_id = CASE WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL ELSE moderation_cases.assigned_user_id END,
    resolved_by_user_id = CASE WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL ELSE moderation_cases.resolved_by_user_id END,
    resolution_code = CASE WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL ELSE moderation_cases.resolution_code END,
    resolution_note = CASE WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL ELSE moderation_cases.resolution_note END,
    resolved_at = CASE WHEN moderation_cases.status IN ('resolved', 'dismissed') THEN NULL ELSE moderation_cases.resolved_at END,
    last_reported_at = NOW(), updated_at = NOW()
RETURNING id, status
SQL);
            $statement->execute(['scope' => self::scope($roomId), 'subject_id' => $userId, 'room_id' => $roomId]);
            $case = $statement->fetch();
            if (!is_array($case)) {
                throw new RuntimeException('Person case creation did not return a row.');
            }
            $caseId = (int) $case['id'];
            $existing = $this->prepare('SELECT 1 FROM moderation_user_snapshots WHERE case_id = :case_id');
            $existing->execute(['case_id' => $caseId]);
            if ($existing->fetchColumn() === false) {
                $this->snapshot($caseId, $subject, $room);
            }
            $report = $this->prepare(<<<'SQL'
INSERT INTO moderation_reports (case_id, reporter_user_id, category, details)
VALUES (:case_id, :reporter_id, :category, :details)
ON CONFLICT (case_id, reporter_user_id) DO NOTHING RETURNING id
SQL);
            $report->execute(['case_id' => $caseId, 'reporter_id' => $actor->id, 'category' => $category, 'details' => $details]);
            $reportId = $report->fetchColumn();
            if ($reportId === false) {
                throw new ApiException(409, 'already_reported', 'You have already reported this person in this context.');
            }
            (new AuditLogger($this->pdo))->log($actor->id, 'moderation.report_created', 'moderation_case', (string) $caseId,
                ['case_id' => $caseId, 'report_id' => (int) $reportId, 'subject_kind' => 'user',
                    'subject_user_id' => $userId, 'room_id' => $roomId, 'category' => $category], $ipAddress);
            $count = $this->prepare('SELECT COUNT(*) FROM moderation_reports WHERE case_id = :case_id');
            $count->execute(['case_id' => $caseId]);
            $result = ['id' => $caseId, 'status' => (string) $case['status'], 'report_count' => (int) $count->fetchColumn()];
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function requireOther(AuthenticatedUser $actor, int $userId): void
    {
        if ($userId < 1) {
            throw new ApiException(400, 'validation_error', 'user_id must be positive.');
        }
        if ($actor->id === $userId) {
            throw self::notAllowed();
        }
    }

    /** @return array<string,mixed> */
    private function subject(int $userId, bool $lock): array
    {
        // Keep copied data stable while permitting FK checks by other reports/review actions.
        $statement = $this->prepare("SELECT id, username, avatar_key, avatar_updated_at FROM users WHERE id = :id AND account_state = 'active'"
            . ($lock ? ' FOR SHARE' : ''));
        $statement->execute(['id' => $userId]);
        $subject = $statement->fetch();
        if (!is_array($subject)) {
            throw new ApiException(404, 'user_not_found', 'This account is not available.');
        }
        return $subject;
    }

    private function context(AuthenticatedUser $actor, int $userId, ?int $roomId): ?Room
    {
        if ($roomId === null) {
            if ($actor->guest) {
                throw self::notAllowed();
            }
            return null; // Every active profile is visible to signed-in members today.
        }
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        if ($this->pdo->inTransaction()) {
            // Room before subject: serialize with room deletion and its guest cleanup.
            $lock = $this->prepare('SELECT id FROM rooms WHERE id = :id AND deleted_at IS NULL FOR SHARE');
            $lock->execute(['id' => $roomId]);
            if ($lock->fetchColumn() === false) {
                throw self::notAllowed();
            }
        }
        $rooms = new RoomRepository($this->pdo);
        $room = $rooms->findForUser($roomId, $actor->id);
        if ($room === null || !RoomAuthorization::canReadHistory($actor, $room)) {
            throw self::notAllowed();
        }
        try {
            (new RoomEligibility($rooms))->requireMinimumAge($actor, $room);
        } catch (ApiException) {
            throw self::notAllowed();
        }
        $link = $this->prepare(<<<'SQL'
SELECT 1 WHERE
    EXISTS (SELECT 1 FROM room_members WHERE room_id = :room_id AND user_id = :subject_id)
    OR EXISTS (SELECT 1 FROM room_presence WHERE room_id = :room_id AND user_id = :subject_id
        AND lease_expires_at > NOW()
        AND (CAST(:timeout AS integer) = 0 OR last_interaction_at > NOW() - CAST(:window AS integer) * INTERVAL '1 second'))
    OR EXISTS (SELECT 1 FROM room_messages WHERE room_id = :room_id AND sender_id = :subject_id)
SQL);
        $link->execute(['room_id' => $roomId, 'subject_id' => $userId,
            'timeout' => $room->inactivityTimeoutSeconds, 'window' => $room->inactivityTimeoutSeconds]);
        if ($link->fetchColumn() === false) {
            throw self::notAllowed();
        }
        return $room;
    }

    /** @param array<string,mixed> $subject */
    private function snapshot(int $caseId, array $subject, ?Room $room): void
    {
        $image = null;
        if ($subject['avatar_key'] !== null) {
            $path = (new AttachmentFileStore($this->config))->pathForKey((string) $subject['avatar_key']);
            $image = @file_get_contents($path, false, null, 0, self::MAX_SNAPSHOT_BYTES + 1);
            if (!is_string($image) || $image === '' || strlen($image) > self::MAX_SNAPSHOT_BYTES) {
                throw new ApiException(409, 'report_snapshot_unavailable', 'The profile picture could not be preserved. Please try again later.');
            }
        }
        $statement = $this->prepare(<<<'SQL'
INSERT INTO moderation_user_snapshots (case_id, username, avatar_key, avatar_updated_at, avatar_image, room_id, room_name)
VALUES (:case_id, :username, :avatar_key, :avatar_updated_at, :avatar_image, :room_id, :room_name)
SQL);
        $statement->bindValue(':case_id', $caseId, PDO::PARAM_INT);
        $statement->bindValue(':username', (string) $subject['username']);
        $statement->bindValue(':avatar_key', $subject['avatar_key'], $subject['avatar_key'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':avatar_updated_at', $subject['avatar_updated_at'], $subject['avatar_updated_at'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':avatar_image', $image, $image === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $statement->bindValue(':room_id', $room?->id, $room === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':room_name', $room?->name, $room === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    private static function scope(?int $roomId): string
    {
        return $roomId === null ? 'global' : 'room:' . $roomId;
    }

    private static function notAllowed(): ApiException
    {
        return new ApiException(403, 'report_not_allowed', 'You cannot report this person from here.');
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare a person-report query.');
        }
        return $statement;
    }
}
