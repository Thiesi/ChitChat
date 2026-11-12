<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;

/**
 * Serializes everything that claims a username or reserves one (#164).
 *
 * A name can move between users.username_canonical and username_reservations
 * in one transaction, which no unique index spans. Every path that claims a
 * canonical name or reserves one therefore takes a transaction-scoped advisory
 * lock per name before it checks. Locks are taken in sorted order within one
 * call. Callers that lock an account row do so before these locks. Placeholder
 * names are rejected before any lock and are only ever locked last, by the
 * moderator action, so they cannot take part in a cycle.
 */
final class UsernameClaims
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function lock(string ...$canonicals): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Username claims must be locked inside a transaction.');
        }
        $names = array_values(array_unique($canonicals));
        sort($names, SORT_STRING);
        $statement = $this->pdo->prepare("SELECT pg_advisory_xact_lock(hashtext('username:' || CAST(:name AS text)))");
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare username claim lock.');
        }
        foreach ($names as $name) {
            $statement->execute(['name' => $name]);
        }
    }

    /**
     * A placeholder-pattern or reserved name is reported exactly like a taken one,
     * so that moderation history is not disclosed.
     */
    public static function assertNotPlaceholder(string $username): void
    {
        if (Username::isPlaceholder($username)) {
            throw self::taken();
        }
    }

    /** Call with the name's claim lock held. Taken-by-another-account is left to the unique index. */
    public function assertNotReserved(string $canonical): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM username_reservations
WHERE username_canonical = :canonical
  AND (reserved_until IS NULL OR reserved_until > NOW())
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare username reservation lookup.');
        }
        $statement->execute(['canonical' => $canonical]);
        if ($statement->fetchColumn() !== false) {
            throw self::taken();
        }
    }

    /**
     * The account's pending reservation (held while a new name was required) now
     * runs for username_reservation_days; with 0 days it ends at once. Releasing a
     * name needs no claim lock: a concurrent claim either still sees the row and
     * is refused, or runs after this commits.
     */
    public function startReservationPeriod(int $userId): void
    {
        $release = $this->pdo->prepare(<<<'SQL'
DELETE FROM username_reservations
WHERE user_id = :user_id AND reserved_until IS NULL
  AND (SELECT username_reservation_days FROM system_settings WHERE id = 1) = 0
SQL);
        $start = $this->pdo->prepare(<<<'SQL'
UPDATE username_reservations
SET reserved_until = NOW() + make_interval(days => (SELECT username_reservation_days FROM system_settings WHERE id = 1))
WHERE user_id = :user_id AND reserved_until IS NULL
SQL);
        if ($release === false || $start === false) {
            throw new RuntimeException('Unable to prepare username reservation update.');
        }
        $release->execute(['user_id' => $userId]);
        $start->execute(['user_id' => $userId]);
    }

    public static function taken(): ApiException
    {
        return new ApiException(409, 'username_taken', 'That username is already registered.');
    }
}
