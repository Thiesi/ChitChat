<?php

declare(strict_types=1);

namespace ChitChat\Account;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use JsonException;
use PDO;
use RuntimeException;

final class PrivacyNotificationService
{
    /** @var array<string, string> */
    private const POLICY_LABELS = [
        'registration_enabled' => 'Account registration',
        'mfa_required_for_admin_roles' => 'MFA requirement for administrative roles',
        'room_message_retention_days' => 'Room-message retention (days)',
        'direct_message_retention_days' => 'Direct-message retention (days)',
        'audit_retention_days' => 'Audit retention (days)',
        'deleted_attachment_retention_days' => 'Deleted-attachment retention (days)',
        'orphan_attachment_grace_hours' => 'Orphan-attachment grace period (hours)',
        'realtime_event_retention_hours' => 'Realtime-event retention (hours)',
        'login_attempt_retention_days' => 'Login-attempt retention (days)',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   notifications:list<array{
     *     id:int,
     *     kind:string,
     *     title:string,
     *     message:string,
     *     details:list<string>,
     *     read:bool,
     *     read_at:?string,
     *     created_at:string
     *   }>,
     *   unread_count:int
     * }
     */
    public function timeline(
        AuthenticatedUser $actor,
        ?int $beforeId = null,
        int $limit = 50,
    ): array {
        if ($beforeId !== null && $beforeId < 1) {
            throw new ApiException(400, 'validation_error', 'before_id must be positive.');
        }
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 100.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id,
       kind,
       context_json::text AS context,
       read_at,
       created_at
FROM account_notifications
WHERE user_id = :user_id
  AND (CAST(:has_before AS integer) = 0 OR id < :before_id)
ORDER BY id DESC
LIMIT :limit
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare privacy-notification timeline.');
        }
        $statement->bindValue(':user_id', $actor->id, PDO::PARAM_INT);
        $statement->bindValue(':has_before', $beforeId === null ? 0 : 1, PDO::PARAM_INT);
        $statement->bindValue(':before_id', $beforeId ?? PHP_INT_MAX, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $notifications = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $notifications[] = $this->present($row);
            }
        }

        return [
            'notifications' => $notifications,
            'unread_count' => $this->unreadCount($actor),
        ];
    }

    /** @param list<mixed> $ids */
    public function markRead(AuthenticatedUser $actor, array $ids): int
    {
        if (count($ids) > 100) {
            throw new ApiException(400, 'validation_error', 'At most 100 notification IDs may be updated at once.');
        }

        $normalized = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new ApiException(400, 'validation_error', 'Every notification ID must be a positive integer.');
            }
            $normalized[$id] = $id;
        }
        $normalized = array_values($normalized);
        if ($normalized === []) {
            return 0;
        }

        $placeholders = [];
        $parameters = ['user_id' => $actor->id];
        foreach ($normalized as $index => $id) {
            $name = 'notification_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        $statement = $this->pdo->prepare(sprintf(
            'UPDATE account_notifications SET read_at = COALESCE(read_at, NOW()) WHERE user_id = :user_id AND id IN (%s) AND read_at IS NULL',
            implode(', ', $placeholders),
        ));
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare privacy-notification read update.');
        }
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    public function markAllRead(AuthenticatedUser $actor): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE account_notifications
SET read_at = NOW()
WHERE user_id = :user_id
  AND read_at IS NULL
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare privacy-notification bulk read update.');
        }
        $statement->execute(['user_id' => $actor->id]);

        return $statement->rowCount();
    }

    public function unreadCount(AuthenticatedUser $actor): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM account_notifications
WHERE user_id = :user_id
  AND read_at IS NULL
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare privacy-notification unread count.');
        }
        $statement->execute(['user_id' => $actor->id]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id:int,
     *   kind:string,
     *   title:string,
     *   message:string,
     *   details:list<string>,
     *   read:bool,
     *   read_at:?string,
     *   created_at:string
     * }
     */
    private function present(array $row): array
    {
        $kind = (string) $row['kind'];
        $context = $this->decodeContext((string) $row['context']);
        $title = 'Privacy notification';
        $message = 'A privacy- or security-relevant account event occurred.';
        $details = [];

        if ($kind === 'revision_review') {
            $title = 'Message revision history reviewed';
            if (($context['message_kind'] ?? null) === 'room') {
                $roomName = $this->nonEmptyString($context['room_name'] ?? null);
                $message = $roomName === null
                    ? 'An administrator reviewed retained revision history for one of your room messages.'
                    : sprintf('An administrator reviewed retained revision history for one of your messages in “%s”.', $roomName);
            } else {
                $message = 'An administrator reviewed retained revision history for a direct-message conversation you participated in.';
            }
        } elseif ($kind === 'moderator_message_deleted') {
            $title = 'Message deleted by a moderator';
            $roomName = $this->nonEmptyString($context['room_name'] ?? null);
            $message = $roomName === null
                ? 'A moderator deleted one of your room messages.'
                : sprintf('A moderator deleted one of your messages in “%s”.', $roomName);
        } elseif ($kind === 'admin_password_reset') {
            $title = 'Password reset by an administrator';
            $message = 'An administrator reset your password and invalidated your existing signed-in sessions.';
        } elseif ($kind === 'system_policy_changed') {
            $title = 'Installation policy changed';
            $message = 'A Super-Administrator changed one or more installation policies.';
            $details = $this->policyDetails($context);
        }

        $readAt = $row['read_at'] === null ? null : (string) $row['read_at'];

        return [
            'id' => (int) $row['id'],
            'kind' => $kind,
            'title' => $title,
            'message' => $message,
            'details' => $details,
            'read' => $readAt !== null,
            'read_at' => $readAt,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @return array<string, mixed> */
    private function decodeContext(string $encoded): array
    {
        try {
            $decoded = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored privacy-notification context is invalid.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $context @return list<string> */
    private function policyDetails(array $context): array
    {
        $old = is_array($context['old'] ?? null) ? $context['old'] : [];
        $new = is_array($context['new'] ?? null) ? $context['new'] : [];
        $details = [];

        foreach (self::POLICY_LABELS as $key => $label) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;
            if ($oldValue === $newValue) {
                continue;
            }
            $details[] = sprintf(
                '%s: %s → %s',
                $label,
                $this->formatPolicyValue($oldValue),
                $this->formatPolicyValue($newValue),
            );
        }

        return $details;
    }

    private function formatPolicyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return 'not set';
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
