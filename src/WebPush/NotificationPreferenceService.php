<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Http\ApiException;
use DateTimeZone;
use PDO;
use RuntimeException;

final class NotificationPreferenceService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{
     *   mentioned_push_enabled:bool,
     *   quiet_hours:?array{start:int, end:int, timezone:string}
     * }
     */
    public function get(int $userId): array
    {
        $preferenceStatement = $this->pdo->prepare(
            "SELECT push_enabled FROM notification_preferences WHERE user_id = :user_id AND category = 'mentioned'",
        );
        if ($preferenceStatement === false) {
            throw new RuntimeException('Unable to prepare notification preference lookup.');
        }
        $preferenceStatement->execute(['user_id' => $userId]);
        $preferenceRow = $preferenceStatement->fetch();
        $mentionedPushEnabled = is_array($preferenceRow)
            ? $this->databaseBoolean($preferenceRow['push_enabled'])
            : true;

        $quietHoursStatement = $this->pdo->prepare(<<<'SQL'
SELECT push_quiet_hours_start, push_quiet_hours_end, push_quiet_hours_timezone
FROM users
WHERE id = :user_id
SQL);
        if ($quietHoursStatement === false) {
            throw new RuntimeException('Unable to prepare quiet-hours lookup.');
        }
        $quietHoursStatement->execute(['user_id' => $userId]);
        $row = $quietHoursStatement->fetch();
        $quietHours = null;
        if (
            is_array($row)
            && $row['push_quiet_hours_start'] !== null
            && $row['push_quiet_hours_end'] !== null
            && $row['push_quiet_hours_timezone'] !== null
        ) {
            $quietHours = [
                'start' => (int) $row['push_quiet_hours_start'],
                'end' => (int) $row['push_quiet_hours_end'],
                'timezone' => (string) $row['push_quiet_hours_timezone'],
            ];
        }

        return [
            'mentioned_push_enabled' => $mentionedPushEnabled,
            'quiet_hours' => $quietHours,
        ];
    }

    public function setMentionedPushEnabled(int $userId, bool $enabled): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO notification_preferences (user_id, category, push_enabled)
VALUES (:user_id, 'mentioned', :enabled)
ON CONFLICT (user_id, category) DO UPDATE SET push_enabled = EXCLUDED.push_enabled
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare notification preference update.');
        }
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
        $statement->execute();
    }

    public function setQuietHours(int $userId, ?int $start, ?int $end, ?string $timezone): void
    {
        $timezone = $timezone === null ? null : trim($timezone);
        $provided = array_filter([$start, $end, $timezone], static fn (mixed $value): bool => $value !== null);
        if (count($provided) !== 0 && count($provided) !== 3) {
            throw new ApiException(
                400,
                'validation_error',
                'quiet_hours_start, quiet_hours_end, and quiet_hours_timezone must be set together, or all cleared.',
            );
        }
        if ($start !== null && ($start < 0 || $start > 23)) {
            throw new ApiException(400, 'validation_error', 'quiet_hours_start must be between 0 and 23.');
        }
        if ($end !== null && ($end < 0 || $end > 23)) {
            throw new ApiException(400, 'validation_error', 'quiet_hours_end must be between 0 and 23.');
        }
        if ($timezone !== null && !in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new ApiException(400, 'validation_error', 'quiet_hours_timezone must be a valid IANA timezone identifier.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET push_quiet_hours_start = :start,
    push_quiet_hours_end = :end,
    push_quiet_hours_timezone = :timezone
WHERE id = :user_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare quiet-hours update.');
        }
        $statement->execute([
            'start' => $start,
            'end' => $end,
            'timezone' => $timezone,
            'user_id' => $userId,
        ]);
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
