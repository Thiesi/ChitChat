<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Admin\LockdownService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\RoomAuthorization;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Guests look around without an account. A guest is a real users row with
 * account_kind 'guest', named "Guest NNNN". It lasts one browser session:
 * it ends after IDLE_SECONDS without a request, after MAX_SECONDS at most,
 * when the guest leaves, when a moderator ends it, or when a
 * Super-Administrator switches guest access off. An ended guest becomes a
 * closed account that keeps its name, so its messages stay readable.
 */
final class GuestService
{
    public const IDLE_SECONDS = 7_200;
    public const MAX_SECONDS = 86_400;
    /** Concurrent guests from one connection (IP address). */
    public const MAX_PER_CONNECTION = 3;
    /** How long "Block guests from this connection" may last, in seconds. */
    public const BLOCK_DURATIONS = [3_600, 86_400, 604_800];

    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly UserRepository $users;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
        $this->users = new UserRepository($pdo);
    }

    public function enabled(): bool
    {
        $statement = $this->pdo->query('SELECT guest_access_enabled::int FROM system_settings WHERE id = 1');
        if ($statement === false) {
            throw new RuntimeException('Unable to read the guest access setting.');
        }

        return (int) $statement->fetchColumn() === 1;
    }

    /** Whether the sign-in page offers "Look around as a guest": on, and no lockdown. */
    public function available(): bool
    {
        return $this->enabled() && !(new LockdownService($this->pdo))->status()['enabled'];
    }

    public function start(string $ipAddress): AuthenticatedUser
    {
        if (!$this->enabled()) {
            throw new ApiException(403, 'guest_access_disabled', 'Guest access is not available.');
        }
        if ((new LockdownService($this->pdo))->status()['enabled']) {
            throw new ApiException(403, 'guest_access_paused', 'Guest access is paused during maintenance.');
        }
        // Tidy up first, so ended guests do not count against the connection.
        $this->endExpired();

        $this->pdo->beginTransaction();
        try {
            // One guest start per connection at a time keeps the cap exact.
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:key))');
            if ($lock === false) {
                throw new RuntimeException('Unable to prepare the guest start lock.');
            }
            $lock->execute(['key' => 'guest-start:' . $ipAddress]);

            if ($this->blockedUntil($ipAddress) !== null) {
                throw new ApiException(403, 'guest_access_blocked', 'Guest access is not available from this connection right now. You can still create an account.');
            }
            if ($this->activeFromConnection($ipAddress) >= self::MAX_PER_CONNECTION) {
                throw new ApiException(429, 'guest_limit_reached', 'There are already several guests from this connection. Please try again later.');
            }

            $number = $this->pdo->query("SELECT nextval('guest_number_seq')");
            if ($number === false) {
                throw new RuntimeException('Unable to number the guest.');
            }
            $guestNumber = (int) $number->fetchColumn();
            $username = sprintf('Guest %04d', $guestNumber);

            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO users (
    username,
    username_canonical,
    password_hash,
    has_password,
    account_kind,
    guest_expires_at,
    guest_last_seen_at,
    guest_ip_address,
    last_login_at
)
VALUES (
    :username,
    :canonical,
    :password_hash,
    FALSE,
    'guest',
    NOW() + CAST(:max_seconds AS integer) * INTERVAL '1 second',
    NOW(),
    :ip_address,
    NOW()
)
RETURNING id
SQL);
            if ($insert === false) {
                throw new RuntimeException('Unable to prepare guest creation.');
            }
            $insert->execute([
                'username' => $username,
                // The space keeps it apart from every real username.
                'canonical' => strtolower($username),
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'max_seconds' => self::MAX_SECONDS,
                'ip_address' => $ipAddress,
            ]);
            $userId = (int) $insert->fetchColumn();

            $welcome = $this->pdo->prepare(
                "INSERT INTO account_notifications (user_id, kind, context_json) VALUES (:user_id, 'guest_welcome', CAST(:context AS jsonb))",
            );
            if ($welcome === false) {
                throw new RuntimeException('Unable to prepare the guest welcome.');
            }
            $welcome->execute(['user_id' => $userId, 'context' => json_encode(['username' => $username], JSON_THROW_ON_ERROR)]);

            $this->audit->log($userId, 'guest.session_started', 'user', (string) $userId, ['username' => $username], $ipAddress);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        $guest = $this->users->findAuthenticatedById($userId);
        if ($guest === null) {
            throw new RuntimeException('The new guest could not be loaded.');
        }

        return $guest;
    }

    /**
     * Whether a guest session may continue: guest access is on, the guest
     * has not expired or idled out. Marks the guest as seen (at most once a
     * minute, so busy pages do not write on every request).
     */
    public function touch(int $userId): bool
    {
        return $this->users->touchGuest($userId, self::IDLE_SECONDS);
    }

    /** The guest leaves on their own ("Leave"). */
    public function leave(AuthenticatedUser $guest, string $ipAddress): void
    {
        if (!$guest->guest) {
            return;
        }
        $this->transaction(function () use ($guest, $ipAddress): void {
            if ($this->close([$guest->id]) !== []) {
                $this->audit->log($guest->id, 'guest.session_left', 'user', (string) $guest->id, [], $ipAddress);
            }
        });
    }

    /** A moderator ends one guest's session at once. */
    public function endByModerator(AuthenticatedUser $actor, int $guestUserId, string $ipAddress): void
    {
        $this->requireGuestModerator($actor);
        $guest = $this->requireActiveGuest($guestUserId);
        $this->transaction(function () use ($actor, $guest, $ipAddress): void {
            $this->close([$guest->id], $actor);
            $this->audit->log($actor->id, 'guest.session_ended', 'user', (string) $guest->id, ['username' => $guest->username], $ipAddress);
        });
    }

    /**
     * No new guests from this guest's connection for a while, and every
     * guest from it ends now. The moderator never learns the address.
     */
    public function blockConnection(
        AuthenticatedUser $actor,
        int $guestUserId,
        int $durationSeconds,
        string $reason,
        string $ipAddress,
    ): void {
        $this->requireGuestModerator($actor);
        if (!in_array($durationSeconds, self::BLOCK_DURATIONS, true)) {
            throw new ApiException(400, 'validation_error', 'duration_seconds must be one hour, one day or one week.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new ApiException(400, 'validation_error', 'reason must contain at most 500 characters.');
        }
        $guest = $this->requireActiveGuest($guestUserId);
        $connection = $this->connectionOf($guest->id);
        if ($connection === null) {
            throw new ApiException(409, 'guest_connection_unknown', 'This guest’s connection is not known, so it cannot be blocked. End the session instead.');
        }

        $this->transaction(function () use ($actor, $guest, $connection, $durationSeconds, $reason, $ipAddress): void {
            $block = $this->pdo->prepare(<<<'SQL'
INSERT INTO guest_blocks (ip_address, created_by, reason, expires_at)
VALUES (:ip_address, :created_by, :reason, NOW() + CAST(:seconds AS integer) * INTERVAL '1 second')
RETURNING id
SQL);
            if ($block === false) {
                throw new RuntimeException('Unable to prepare the guest block.');
            }
            $block->execute([
                'ip_address' => $connection,
                'created_by' => $actor->id,
                'reason' => $reason,
                'seconds' => $durationSeconds,
            ]);
            $blockId = (int) $block->fetchColumn();

            $ended = $this->close($this->activeIdsFromConnection($connection), $actor);
            $this->audit->log($actor->id, 'guest.connection_blocked', 'user', (string) $guest->id, [
                'username' => $guest->username,
                'block_id' => $blockId,
                'duration_seconds' => $durationSeconds,
                'reason' => $reason,
                'guests_ended' => count($ended),
            ], $ipAddress);
        });
    }

    /** Guest access was switched off: every guest ends. Runs inside the caller's transaction. */
    public function endAll(?AuthenticatedUser $actor): int
    {
        $statement = $this->pdo->query("SELECT id FROM users WHERE account_kind = 'guest' AND account_state = 'active'");
        if ($statement === false) {
            throw new RuntimeException('Unable to list active guests.');
        }

        return count($this->close(array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN))), $actor));
    }

    /** Closes guests past their idle time or lifetime; the maintenance job and every guest start call it. */
    public function endExpired(): int
    {
        return $this->transaction(fn (): int => count($this->close($this->expiredIds())));
    }

    public function expiredCount(): int
    {
        return count($this->expiredIds());
    }

    /** Removes guest blocks that have run out. */
    public function purgeExpiredBlocks(bool $dryRun): int
    {
        $sql = $dryRun
            ? 'SELECT COUNT(*) FROM guest_blocks WHERE expires_at <= NOW()'
            : 'DELETE FROM guest_blocks WHERE expires_at <= NOW()';
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to clean up guest blocks.');
        }

        return $dryRun ? (int) $statement->fetchColumn() : $statement->rowCount();
    }

    /** When the block on a connection ends, or null when it is not blocked. */
    public function blockedUntil(string $ipAddress): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT MAX(expires_at) FROM guest_blocks WHERE ip_address = :ip_address AND expires_at > NOW()',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the guest block lookup.');
        }
        $statement->execute(['ip_address' => $ipAddress]);
        $until = $statement->fetchColumn();

        return is_string($until) ? $until : null;
    }

    /**
     * Closes the given guests: the account keeps its name for history, and
     * everything that only mattered while it was around goes.
     *
     * @param list<int> $userIds
     * @return list<int> the guests that were still active
     */
    private function close(array $userIds, ?AuthenticatedUser $actor = null): array
    {
        if ($userIds === []) {
            return [];
        }
        $list = '{' . implode(',', array_map('intval', $userIds)) . '}';
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET account_state = 'closed',
    closure_requested_at = NOW(),
    closure_finalizes_at = NOW(),
    closed_at = NOW(),
    guest_ip_address = NULL,
    session_version = session_version + 1,
    updated_at = NOW()
WHERE id = ANY(CAST(:ids AS bigint[]))
  AND account_kind = 'guest'
  AND account_state = 'active'
RETURNING id, session_version
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare guest closing.');
        }
        $statement->execute(['ids' => $list]);
        /** @var array<int, int> $closed */
        $closed = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $closed[(int) $row['id']] = (int) $row['session_version'];
            }
        }
        if ($closed === []) {
            return [];
        }
        $closedList = '{' . implode(',', array_keys($closed)) . '}';

        $presence = $this->pdo->prepare(
            'DELETE FROM room_presence WHERE user_id = ANY(CAST(:ids AS bigint[])) RETURNING room_id, user_id',
        );
        if ($presence === false) {
            throw new RuntimeException('Unable to prepare guest presence cleanup.');
        }
        $presence->execute(['ids' => $closedList]);
        $left = [];
        foreach ($presence->fetchAll() as $row) {
            if (is_array($row) && $row['room_id'] !== null) {
                $left[(int) $row['room_id'] . ':' . (int) $row['user_id']] = [(int) $row['room_id'], (int) $row['user_id']];
            }
        }
        foreach ($left as [$roomId, $userId]) {
            $this->events->publish(
                type: 'presence_changed',
                payload: ['room_id' => $roomId, 'user_id' => $userId],
                roomId: $roomId,
                actorUserId: $userId,
            );
        }

        foreach ([
            'DELETE FROM room_members WHERE user_id = ANY(CAST(:ids AS bigint[]))',
            'DELETE FROM room_invitations WHERE user_id = ANY(CAST(:ids AS bigint[]))',
            'DELETE FROM room_reads WHERE user_id = ANY(CAST(:ids AS bigint[]))',
            'DELETE FROM user_ignores WHERE user_id = ANY(CAST(:ids AS bigint[])) OR ignored_user_id = ANY(CAST(:ids AS bigint[]))',
            'DELETE FROM account_notifications WHERE user_id = ANY(CAST(:ids AS bigint[]))',
        ] as $sql) {
            $cleanup = $this->pdo->prepare($sql);
            if ($cleanup === false) {
                throw new RuntimeException('Unable to prepare guest cleanup.');
            }
            $cleanup->execute(['ids' => $closedList]);
        }

        // A page that is still open signs out at once.
        foreach ($closed as $userId => $sessionVersion) {
            $this->events->publish(
                type: 'forced_logout',
                payload: ['action' => 'guest_ended', 'reason' => '', 'session_version' => $sessionVersion],
                targetUserId: $userId,
                actorUserId: $actor?->id,
                expiresAt: new DateTimeImmutable('+5 minutes'),
            );
        }

        return array_keys($closed);
    }

    /** @return list<int> */
    private function expiredIds(): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id
FROM users
WHERE account_kind = 'guest'
  AND account_state = 'active'
  AND (
      guest_expires_at <= NOW()
      OR guest_last_seen_at <= NOW() - CAST(:idle_seconds AS integer) * INTERVAL '1 second'
  )
ORDER BY id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the expired-guest lookup.');
        }
        $statement->execute(['idle_seconds' => self::IDLE_SECONDS]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function activeFromConnection(string $ipAddress): int
    {
        return count($this->activeIdsFromConnection($ipAddress));
    }

    /** @return list<int> */
    private function activeIdsFromConnection(string $ipAddress): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM users WHERE account_kind = 'guest' AND account_state = 'active' AND guest_ip_address = :ip_address",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the guest connection lookup.');
        }
        $statement->execute(['ip_address' => $ipAddress]);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function connectionOf(int $guestUserId): ?string
    {
        $statement = $this->pdo->prepare('SELECT guest_ip_address FROM users WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare the guest connection lookup.');
        }
        $statement->execute(['id' => $guestUserId]);
        $connection = $statement->fetchColumn();

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function requireGuestModerator(AuthenticatedUser $actor): void
    {
        if (!RoomAuthorization::canModerateAnyRoom($actor)) {
            throw new ApiException(403, 'forbidden', 'Only global moderators and administrators can end guest sessions.');
        }
    }

    private function requireActiveGuest(int $userId): AuthenticatedUser
    {
        if ($userId < 1) {
            throw new ApiException(400, 'validation_error', 'user_id must be positive.');
        }
        $guest = $this->users->findAuthenticatedById($userId);
        if ($guest === null || !$guest->guest) {
            throw new ApiException(404, 'guest_not_found', 'This guest has already left.');
        }

        return $guest;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
