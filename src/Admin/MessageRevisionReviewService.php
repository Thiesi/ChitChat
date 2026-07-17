<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

final class MessageRevisionReviewService
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->audit = new AuditLogger($pdo);
    }

    /**
     * @return array{
     *   kind:'room'|'direct',
     *   message:array<string, mixed>,
     *   revisions:list<array<string, mixed>>
     * }
     */
    public function review(
        AuthenticatedUser $actor,
        string $kindInput,
        int $messageId,
        string $reasonInput,
        string $ipAddress,
    ): array {
        $this->requireReviewAccess($actor);
        $kind = strtolower(trim($kindInput));
        if (!in_array($kind, ['room', 'direct'], true)) {
            throw new ApiException(400, 'validation_error', 'kind must be room or direct.');
        }
        if ($messageId < 1) {
            throw new ApiException(400, 'validation_error', 'message_id must be positive.');
        }

        $reason = trim($reasonInput);
        $reasonLength = mb_strlen($reason, 'UTF-8');
        if ($reasonLength < 10 || $reasonLength > 500) {
            throw new ApiException(
                400,
                'revision_review_reason_required',
                'Review reason must contain 10-500 characters.',
            );
        }

        $this->pdo->beginTransaction();
        try {
            $review = $kind === 'room'
                ? $this->roomReview($messageId)
                : $this->directReview($messageId);
            if ($review['revisions'] === []) {
                throw new ApiException(
                    404,
                    'revision_history_not_found',
                    'No retained revision history was found for this message.',
                );
            }

            $revisionIds = [];
            $revisionActions = [];
            foreach ($review['revisions'] as $revision) {
                $revisionIds[] = (int) $revision['id'];
                $revisionActions[] = (string) $revision['action'];
            }
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'admin.message_revisions_reviewed',
                subjectType: 'message_revision_history',
                subjectId: $kind . ':' . $messageId,
                metadata: array_merge(
                    [
                        'message_kind' => $kind,
                        'message_id' => $messageId,
                        'reason' => $reason,
                        'revision_count' => count($review['revisions']),
                        'revision_ids' => $revisionIds,
                        'revision_actions' => $revisionActions,
                    ],
                    $review['audit_context'],
                ),
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();

            return [
                'kind' => $kind,
                'message' => $review['message'],
                'revisions' => $review['revisions'],
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   message:array<string, mixed>,
     *   revisions:list<array<string, mixed>>,
     *   audit_context:array<string, mixed>
     * }
     */
    private function roomReview(int $messageId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT rm.id,
       rm.room_id,
       r.room_key,
       r.name AS room_name,
       rm.sender_id,
       sender.username AS sender_username,
       rm.message_type,
       rm.created_at,
       rm.edited_at,
       rm.edited_by,
       editor.username AS editor_username,
       rm.deleted_at,
       rm.deleted_by,
       deleter.username AS deleter_username
FROM room_messages rm
JOIN rooms r ON r.id = rm.room_id
LEFT JOIN users sender ON sender.id = rm.sender_id
LEFT JOIN users editor ON editor.id = rm.edited_by
LEFT JOIN users deleter ON deleter.id = rm.deleted_by
WHERE rm.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message revision review.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Room message not found.');
        }

        $revisions = $this->roomRevisions($messageId);
        $message = [
            'id' => (int) $row['id'],
            'room' => [
                'id' => (int) $row['room_id'],
                'key' => (string) $row['room_key'],
                'name' => (string) $row['room_name'],
            ],
            'author' => $this->nullableUser($row['sender_id'], $row['sender_username']),
            'message_type' => (string) $row['message_type'],
            'created_at' => (string) $row['created_at'],
            'edited_at' => $this->nullableString($row['edited_at']),
            'last_editor' => $this->nullableUser($row['edited_by'], $row['editor_username']),
            'deleted_at' => $this->nullableString($row['deleted_at']),
            'deleted_by' => $this->nullableUser($row['deleted_by'], $row['deleter_username']),
        ];

        return [
            'message' => $message,
            'revisions' => $revisions,
            'audit_context' => [
                'room_id' => (int) $row['room_id'],
                'room_key' => (string) $row['room_key'],
                'room_name' => (string) $row['room_name'],
                'author_user_id' => $row['sender_id'] === null ? null : (int) $row['sender_id'],
                'author_username' => $this->nullableString($row['sender_username']),
                'message_type' => (string) $row['message_type'],
                'message_created_at' => (string) $row['created_at'],
                'message_edited_at' => $this->nullableString($row['edited_at']),
                'message_deleted_at' => $this->nullableString($row['deleted_at']),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roomRevisions(int $messageId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT revision.id,
       revision.action,
       revision.actor_user_id,
       actor.username AS actor_username,
       revision.message_type,
       revision.body_before,
       revision.body_after,
       revision.created_at
FROM room_message_revisions revision
LEFT JOIN users actor ON actor.id = revision.actor_user_id
WHERE revision.message_id = :message_id
ORDER BY revision.id ASC
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message revision history.');
        }
        $statement->execute(['message_id' => $messageId]);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'actor' => $this->nullableUser($row['actor_user_id'], $row['actor_username']),
                'message_type' => (string) $row['message_type'],
                'body_before' => (string) $row['body_before'],
                'body_after' => $this->nullableString($row['body_after']),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $result;
    }

    /**
     * @return array{
     *   message:array<string, mixed>,
     *   revisions:list<array<string, mixed>>,
     *   audit_context:array<string, mixed>
     * }
     */
    private function directReview(int $messageId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT dm.id,
       dm.sender_user_id,
       sender.username AS sender_username,
       dm.recipient_user_id,
       recipient.username AS recipient_username,
       dm.created_at,
       dm.edited_at,
       dm.edited_by,
       editor.username AS editor_username,
       dm.deleted_at,
       dm.deleted_by,
       deleter.username AS deleter_username
FROM direct_messages dm
JOIN users sender ON sender.id = dm.sender_user_id
JOIN users recipient ON recipient.id = dm.recipient_user_id
LEFT JOIN users editor ON editor.id = dm.edited_by
LEFT JOIN users deleter ON deleter.id = dm.deleted_by
WHERE dm.id = :id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message revision review.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Direct message not found.');
        }

        $revisions = $this->directRevisions($messageId);
        $message = [
            'id' => (int) $row['id'],
            'sender' => [
                'id' => (int) $row['sender_user_id'],
                'username' => (string) $row['sender_username'],
            ],
            'recipient' => [
                'id' => (int) $row['recipient_user_id'],
                'username' => (string) $row['recipient_username'],
            ],
            'created_at' => (string) $row['created_at'],
            'edited_at' => $this->nullableString($row['edited_at']),
            'last_editor' => $this->nullableUser($row['edited_by'], $row['editor_username']),
            'deleted_at' => $this->nullableString($row['deleted_at']),
            'deleted_by' => $this->nullableUser($row['deleted_by'], $row['deleter_username']),
        ];

        return [
            'message' => $message,
            'revisions' => $revisions,
            'audit_context' => [
                'sender_user_id' => (int) $row['sender_user_id'],
                'sender_username' => (string) $row['sender_username'],
                'recipient_user_id' => (int) $row['recipient_user_id'],
                'recipient_username' => (string) $row['recipient_username'],
                'message_created_at' => (string) $row['created_at'],
                'message_edited_at' => $this->nullableString($row['edited_at']),
                'message_deleted_at' => $this->nullableString($row['deleted_at']),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function directRevisions(int $messageId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT revision.id,
       revision.action,
       revision.actor_user_id,
       actor.username AS actor_username,
       revision.body_before,
       revision.body_after,
       revision.created_at
FROM direct_message_revisions revision
LEFT JOIN users actor ON actor.id = revision.actor_user_id
WHERE revision.message_id = :message_id
ORDER BY revision.id ASC
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message revision history.');
        }
        $statement->execute(['message_id' => $messageId]);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $result[] = [
                'id' => (int) $row['id'],
                'action' => (string) $row['action'],
                'actor' => $this->nullableUser($row['actor_user_id'], $row['actor_username']),
                'body_before' => (string) $row['body_before'],
                'body_after' => $this->nullableString($row['body_after']),
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $result;
    }

    /** @return array{id:?int, username:?string} */
    private function nullableUser(mixed $id, mixed $username): array
    {
        return [
            'id' => $id === null ? null : (int) $id,
            'username' => $this->nullableString($username),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function requireReviewAccess(AuthenticatedUser $actor): void
    {
        if (!$this->config->messageRevisionReviewEnabled) {
            throw new ApiException(
                403,
                'message_revision_review_disabled',
                'Administrative message revision review is disabled.',
            );
        }

        $allowed = $this->config->messageRevisionReviewRole === 'super_admin'
            ? $actor->hasRole('super_admin')
            : $actor->canManageUsers();
        if (!$allowed) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to review message revisions.');
        }
    }
}
