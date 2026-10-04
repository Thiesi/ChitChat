<?php

declare(strict_types=1);

namespace ChitChat\Account;

use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/**
 * Per-account date and time display: a format region and a 12/24-hour
 * clock, each NULL for Automatic. Only regions offered in the client
 * (public/assets/js/datetime.js) are accepted.
 */
final class DisplayPreferenceService
{
    public const DATE_LOCALES = [
        'en-US', 'en-GB', 'en-AU',
        'de-DE', 'de-AT', 'de-CH',
        'fr-FR', 'es-ES', 'it-IT', 'nl-NL', 'pl-PL', 'pt-BR', 'sv-SE', 'ja-JP',
    ];
    public const HOUR_CYCLES = ['h12', 'h23'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{date_locale:?string, hour_cycle:?string} */
    public function get(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT date_locale, hour_cycle FROM users WHERE id = :user_id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare display-preference lookup.');
        }
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();

        return [
            'date_locale' => is_array($row) && is_string($row['date_locale']) ? $row['date_locale'] : null,
            'hour_cycle' => is_array($row) && is_string($row['hour_cycle']) ? $row['hour_cycle'] : null,
        ];
    }

    /** @return array{date_locale:?string, hour_cycle:?string} */
    public function update(int $userId, ?string $dateLocale, ?string $hourCycle): array
    {
        if ($dateLocale !== null && !in_array($dateLocale, self::DATE_LOCALES, true)) {
            throw new ApiException(400, 'validation_error', 'date_locale must be one of the offered format regions, or null.');
        }
        if ($hourCycle !== null && !in_array($hourCycle, self::HOUR_CYCLES, true)) {
            throw new ApiException(400, 'validation_error', 'hour_cycle must be h12, h23, or null.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET date_locale = :date_locale,
    hour_cycle = :hour_cycle,
    updated_at = NOW()
WHERE id = :user_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare display-preference update.');
        }
        $statement->execute([
            'date_locale' => $dateLocale,
            'hour_cycle' => $hourCycle,
            'user_id' => $userId,
        ]);

        return $this->get($userId);
    }
}
