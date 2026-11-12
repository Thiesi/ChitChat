<?php

declare(strict_types=1);

namespace ChitChat\Account;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AccountLocks;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Username;
use ChitChat\Auth\UsernameClaims;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ReportService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\ModerationRank;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * "Require a new username" (#164). A Global Moderator or above replaces an
 * offensive name with a neutral placeholder at once. The account keeps reading
 * and signing in (also with the removed name, which stays reserved), but every
 * gated write is refused until it chooses a new name here.
 *
 * Lock order: account rows first, through AccountLocks::forKeyChange (the
 * target FOR UPDATE, since changing username_canonical is a key update, and
 * the acting moderator FOR KEY SHARE, in numeric ID order), then the username
 * claim locks, then a moderation case. Person reports also lock the account
 * before the case. Every other staff action locks account rows at most FOR NO
 * KEY UPDATE, so it never blocks this action's actor lock (#248).
 */
final class UsernameChangeService
{
    public const REASON_MAX_LENGTH = 500;
    private const GLOBAL_MODERATION_RANK = 3;
    private const PLACEHOLDER_ATTEMPTS = 8;

    private readonly AuditLogger $audit;
    private readonly EventRepository $events;
    private readonly UsernameClaims $claims;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
        $this->claims = new UsernameClaims($pdo);
    }

    /** @return array{user:array{id:int, username:string}, case:?array<string, mixed>} */
    public function requireChange(
        AuthenticatedUser $actor,
        int $userId,
        string $reasonInput,
        ?int $caseId,
        string $ipAddress,
    ): array {
        if ($userId < 1) {
            throw new ApiException(400, 'validation_error', 'user_id must be positive.');
        }
        if ($caseId !== null && $caseId < 1) {
            throw new ApiException(400, 'validation_error', 'case_id must be positive or null.');
        }
        $reason = trim($reasonInput);
        if ($reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason, 'UTF-8') > self::REASON_MAX_LENGTH) {
            throw new ApiException(400, 'validation_error', sprintf('reason must be 1-%d characters of valid UTF-8.', self::REASON_MAX_LENGTH));
        }
        if (ModerationRank::globalRankOf($actor) < self::GLOBAL_MODERATION_RANK || $actor->id === $userId) {
            throw self::forbidden();
        }

        $this->pdo->beginTransaction();
        try {
            (new AccountLocks($this->pdo))->forKeyChange($actor->id, $userId);
            $target = $this->lockAccount($userId);
            if ($target === null || $target['account_state'] === 'closed') {
                throw new ApiException(404, 'user_not_found', 'User not found.');
            }
            if ($target['account_kind'] === 'guest') {
                throw new ApiException(400, 'validation_error', 'Guests have generated names; end the guest visit instead.');
            }
            if (!(new ModerationRank($this->pdo))->globally($actor, $userId)) {
                throw self::forbidden();
            }
            if ($target['username_change_required_at'] !== null) {
                throw new ApiException(409, 'username_change_already_required', 'This account already has to choose a new username.');
            }

            $oldUsername = (string) $target['username'];
            $oldCanonical = (string) $target['username_canonical'];
            $this->claims->lock($oldCanonical);
            $placeholder = $this->freshPlaceholder();

            $this->statement(<<<'SQL'
UPDATE users
SET username = :placeholder,
    username_canonical = :placeholder,
    username_change_required_at = NOW(),
    username_change_reason = :reason,
    username_change_previous_canonical = :previous,
    updated_at = NOW()
WHERE id = :id
SQL, 'required username change')->execute([
                'placeholder' => $placeholder,
                'reason' => $reason,
                'previous' => $oldCanonical,
                'id' => $userId,
            ]);
            // Held until the new name is chosen, whatever username_reservation_days says.
            $this->statement(<<<'SQL'
INSERT INTO username_reservations (username_canonical, user_id, reserved_until)
VALUES (:canonical, :user_id, NULL)
ON CONFLICT (username_canonical)
DO UPDATE SET user_id = EXCLUDED.user_id, reserved_until = NULL, created_at = NOW()
SQL, 'username reservation')->execute(['canonical' => $oldCanonical, 'user_id' => $userId]);
            $this->rewriteNotificationSender($userId, $placeholder);
            $this->statement(
                "INSERT INTO account_notifications (user_id, kind, context_json) VALUES (:user_id, 'username_change_required', CAST(:context AS jsonb))",
                'username-change notification',
            )->execute([
                'user_id' => $userId,
                'context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            if ($caseId !== null) {
                (new ReportService($this->pdo))->resolveForUsernameChange($actor, $caseId, $userId, $ipAddress);
            }
            $this->audit->log($actor->id, 'moderation.username_change_required', 'user', (string) $userId, [
                'old_username' => $oldUsername,
                'placeholder' => $placeholder,
                'reason' => $reason,
                'case_id' => $caseId,
            ], $ipAddress);
            $this->publishRename($userId, $placeholder, $actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return [
            'user' => ['id' => $userId, 'username' => $placeholder],
            'case' => $caseId === null ? null : (new ReportService($this->pdo))->caseDetail($actor, $caseId),
        ];
    }

    /** Chooses the new name; only while a change is required. Not a general self-service rename. */
    public function choose(AuthenticatedUser $actor, string $usernameInput, string $ipAddress): AuthenticatedUser
    {
        if ($actor->guest) {
            throw new ApiException(403, 'forbidden', 'Guests have generated names.');
        }
        $username = Username::display($usernameInput);
        $canonical = strtolower($username);
        UsernameClaims::assertNotPlaceholder($username);

        $this->pdo->beginTransaction();
        try {
            (new AccountLocks($this->pdo))->forKeyChange($actor->id, $actor->id);
            $current = $this->lockAccount($actor->id);
            if ($current === null || $current['account_state'] !== 'active'
                || (int) $current['session_version'] !== $actor->sessionVersion) {
                throw new ApiException(401, 'authentication_required', 'Authentication is required.');
            }
            if ($current['username_change_required_at'] === null) {
                throw new ApiException(409, 'username_change_not_required', 'This account does not have to choose a new username.');
            }
            $placeholder = (string) $current['username'];
            $this->claims->lock($canonical);
            // Includes the account's own removed name, which is reserved while the change is pending.
            $this->claims->assertNotReserved($canonical);

            $this->statement(<<<'SQL'
UPDATE users
SET username = :username,
    username_canonical = :canonical,
    username_change_required_at = NULL,
    username_change_reason = NULL,
    username_change_previous_canonical = NULL,
    updated_at = NOW()
WHERE id = :id
SQL, 'chosen username')->execute(['username' => $username, 'canonical' => $canonical, 'id' => $actor->id]);
            $this->claims->startReservationPeriod($actor->id);
            $this->rewriteNotificationSender($actor->id, $username);
            $this->audit->log($actor->id, 'account.username_changed', 'user', (string) $actor->id, [
                'from' => $placeholder,
                'to' => $username,
                'required' => true,
            ], $ipAddress);
            $this->publishRename($actor->id, $username, $actor->id);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack();
            if ($exception->getCode() === '23505') {
                throw UsernameClaims::taken();
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return (new UserRepository($this->pdo))->findAuthenticatedById($actor->id)
            ?? throw new ApiException(401, 'authentication_required', 'Authentication is required.');
    }

    /** @return ?array<string, mixed> */
    private function lockAccount(int $userId): ?array
    {
        $statement = $this->statement(<<<'SQL'
SELECT id, username, username_canonical, account_kind, account_state, session_version, username_change_required_at
FROM users
WHERE id = :id
FOR UPDATE
SQL, 'account lock');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function freshPlaceholder(): string
    {
        $taken = $this->statement(<<<'SQL'
SELECT 1 FROM users WHERE username_canonical = :name OR username_change_previous_canonical = :name
UNION ALL
SELECT 1 FROM username_reservations WHERE username_canonical = :name
SQL, 'placeholder availability');
        for ($attempt = 0; $attempt < self::PLACEHOLDER_ATTEMPTS; $attempt++) {
            $candidate = Username::placeholder();
            // Placeholder-pattern names are only ever locked last, here.
            $this->claims->lock($candidate);
            $taken->execute(['name' => $candidate]);
            if ($taken->fetchColumn() === false) {
                return $candidate;
            }
        }
        throw new RuntimeException('Unable to find a free placeholder username.');
    }

    /** Stored mention and ping notices (and pushes not yet sent) never show the removed name. */
    private function rewriteNotificationSender(int $userId, string $username): void
    {
        // `??` is PDO's escape for the jsonb `?` operator; the predicate matches account_notifications_sender.
        $this->statement(<<<'SQL'
UPDATE account_notifications
SET context_json = jsonb_set(context_json, '{sender_username}', to_jsonb(CAST(:username AS text)))
WHERE context_json ?? 'sender_username'
  AND context_json->'sender_user_id' = to_jsonb(CAST(:user_id AS bigint))
SQL, 'notification sender rewrite')->execute(['username' => $username, 'user_id' => $userId]);
    }

    private function publishRename(int $userId, string $username, int $actorUserId): void
    {
        // Untargeted and roomless: every connection, as usernames are visible to all members and guests.
        $this->events->publish('user_renamed', ['user' => ['id' => $userId, 'username' => $username]], actorUserId: $actorUserId);
    }

    private function statement(string $sql, string $description): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException(sprintf('Unable to prepare %s.', $description));
        }

        return $statement;
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function forbidden(): ApiException
    {
        return new ApiException(403, 'forbidden', 'You may not require a new username for this account.');
    }
}
