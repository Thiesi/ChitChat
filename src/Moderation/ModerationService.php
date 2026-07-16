<?php

declare(strict_types=1);

namespace ChitChat\Moderation;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\PasswordPolicy;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;
use Exception;
use PDO;
use RuntimeException;
use Throwable;

final class ModerationService
{
    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
    }

    public function kick(
        AuthenticatedUser $actor,
        int $targetUserId,
        string $reason,
        string $ipAddress,
    ): void {
        $target = $this->assertCanManageTarget($actor, $targetUserId);
        $reason = trim($reason);

        $this->pdo->beginTransaction();
        try {
            $sessionVersion = $this->users->bumpSessionVersion($targetUserId);
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'moderation.kick',
                subjectType: 'user',
                subjectId: (string) $targetUserId,
                metadata: ['username' => $target->username, 'reason' => $reason],
                ipAddress: $ipAddress,
            );
            $this->publishForcedLogout(
                $actor,
                $targetUserId,
                'kick',
                $reason,
                $sessionVersion,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function ban(
        AuthenticatedUser $actor,
        int $targetUserId,
        string $reason,
        ?string $expiresAt,
        string $ipAddress,
    ): void {
        $target = $this->assertCanManageTarget($actor, $targetUserId);
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new ApiException(400, 'validation_error', 'Ban reason must not exceed 500 characters.');
        }

        $normalizedExpiry = $this->normalizeExpiry($expiresAt);

        $this->pdo->beginTransaction();
        try {
            $revokeStatement = $this->pdo->prepare(<<<'SQL'
UPDATE user_bans
SET revoked_at = NOW(), revoked_by = :actor
WHERE user_id = :target
  AND revoked_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW())
SQL);
            if ($revokeStatement === false) {
                throw new RuntimeException('Unable to prepare existing-ban revocation.');
            }
            $revokeStatement->execute(['actor' => $actor->id, 'target' => $targetUserId]);

            $banStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO user_bans (user_id, created_by, reason, expires_at)
VALUES (:target, :actor, :reason, :expires_at)
SQL);
            if ($banStatement === false) {
                throw new RuntimeException('Unable to prepare ban creation.');
            }
            $banStatement->execute([
                'target' => $targetUserId,
                'actor' => $actor->id,
                'reason' => $reason,
                'expires_at' => $normalizedExpiry,
            ]);

            $sessionVersion = $this->users->bumpSessionVersion($targetUserId);
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'moderation.ban',
                subjectType: 'user',
                subjectId: (string) $targetUserId,
                metadata: [
                    'username' => $target->username,
                    'reason' => $reason,
                    'expires_at' => $normalizedExpiry,
                ],
                ipAddress: $ipAddress,
            );
            $this->publishForcedLogout(
                $actor,
                $targetUserId,
                'ban',
                $reason,
                $sessionVersion,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function resetPassword(
        AuthenticatedUser $actor,
        int $targetUserId,
        string $newPassword,
        string $ipAddress,
    ): void {
        $target = $this->assertCanManageTarget($actor, $targetUserId);
        PasswordPolicy::validate($newPassword, $target->username);

        $this->pdo->beginTransaction();
        try {
            $sessionVersion = $this->users->updatePassword(
                $targetUserId,
                password_hash($newPassword, PASSWORD_DEFAULT),
            );
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'auth.password_reset_by_admin',
                subjectType: 'user',
                subjectId: (string) $targetUserId,
                metadata: ['username' => $target->username],
                ipAddress: $ipAddress,
            );
            $this->publishForcedLogout(
                $actor,
                $targetUserId,
                'password_reset',
                'Your password was reset by an administrator.',
                $sessionVersion,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function unban(
        AuthenticatedUser $actor,
        int $targetUserId,
        string $ipAddress,
    ): void {
        $target = $this->assertCanManageTarget($actor, $targetUserId);

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE user_bans
SET revoked_at = NOW(), revoked_by = :actor
WHERE user_id = :target
  AND revoked_at IS NULL
  AND (expires_at IS NULL OR expires_at > NOW())
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare unban.');
            }
            $statement->execute(['actor' => $actor->id, 'target' => $targetUserId]);

            $this->audit->log(
                actorUserId: $actor->id,
                action: 'moderation.unban',
                subjectType: 'user',
                subjectId: (string) $targetUserId,
                metadata: ['username' => $target->username],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function publishForcedLogout(
        AuthenticatedUser $actor,
        int $targetUserId,
        string $action,
        string $reason,
        int $sessionVersion,
    ): void {
        $this->events->publish(
            type: 'forced_logout',
            payload: [
                'action' => $action,
                'reason' => $reason,
                'session_version' => $sessionVersion,
            ],
            targetUserId: $targetUserId,
            actorUserId: $actor->id,
            expiresAt: new DateTimeImmutable('+5 minutes'),
        );
    }

    private function assertCanManageTarget(AuthenticatedUser $actor, int $targetUserId): AuthenticatedUser
    {
        if (!$actor->canManageUsers()) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to manage users.');
        }

        if ($targetUserId < 1) {
            throw new ApiException(400, 'validation_error', 'target_user_id must be a positive integer.');
        }

        if ($actor->id === $targetUserId) {
            throw new ApiException(400, 'self_moderation_forbidden', 'You cannot apply this action to your own account.');
        }

        $target = $this->users->findAuthenticatedById($targetUserId);
        if ($target === null) {
            throw new ApiException(404, 'user_not_found', 'Target user not found.');
        }

        if ($target->hasRole('super_admin') && !$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'Only a Super-Administrator may manage another Super-Administrator.');
        }

        return $target;
    }

    private function normalizeExpiry(?string $expiresAt): ?string
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return null;
        }

        try {
            $expiry = new DateTimeImmutable($expiresAt);
        } catch (Exception) {
            throw new ApiException(400, 'validation_error', 'expires_at must be a valid ISO-8601 timestamp.');
        }

        if ($expiry <= new DateTimeImmutable()) {
            throw new ApiException(400, 'validation_error', 'expires_at must be in the future.');
        }

        return $expiry->format(DATE_ATOM);
    }
}
