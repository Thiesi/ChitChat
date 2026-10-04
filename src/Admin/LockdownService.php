<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Maintenance lockdown. While it is on, only Super-Administrators can sign
 * in (so the person doing the maintenance can always get back in to switch
 * it off); everyone else cannot sign in, register, or restore an account,
 * and sees the configured message. Existing sessions are left alone unless
 * the Super-Administrator chose to sign everyone else out.
 */
final class LockdownService
{
    public const MESSAGE_MAX_LENGTH = 500;
    public const DEFAULT_MESSAGE = 'Sign-ins are paused for maintenance. Please try again later.';

    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
    }

    /** @return array{enabled:bool, message:string, custom_message:?string, since:?string} */
    public function status(): array
    {
        $statement = $this->pdo->query(
            'SELECT lockdown_enabled::int AS enabled, lockdown_message, lockdown_since FROM system_settings WHERE id = 1',
        );
        $row = $statement === false ? false : $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('System settings are missing.');
        }
        $custom = is_string($row['lockdown_message']) && $row['lockdown_message'] !== '' ? $row['lockdown_message'] : null;

        return [
            'enabled' => (int) $row['enabled'] === 1,
            'message' => $custom ?? self::DEFAULT_MESSAGE,
            'custom_message' => $custom,
            'since' => $row['lockdown_since'] === null ? null : (string) $row['lockdown_since'],
        ];
    }

    /** Refuses a new sign-in during lockdown unless the account is a Super-Administrator. */
    public function assertSignInAllowed(AuthenticatedUser $user): void
    {
        if ($user->hasRole('super_admin')) {
            return;
        }
        $status = $this->status();
        if ($status['enabled']) {
            throw new ApiException(503, 'maintenance_lockdown', $status['message']);
        }
    }

    /** Refuses registration and account restoration during lockdown. */
    public function assertOpen(): void
    {
        $status = $this->status();
        if ($status['enabled']) {
            throw new ApiException(503, 'maintenance_lockdown', $status['message']);
        }
    }

    /** @return array{enabled:bool, message:string, custom_message:?string, since:?string, signed_out:int} */
    public function update(
        AuthenticatedUser $actor,
        bool $enabled,
        ?string $message,
        bool $signOutOthers,
        string $ipAddress,
    ): array {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'Only Super-Administrators can lock the installation down.');
        }
        $message = $message === null ? null : trim($message);
        if ($message === '') {
            $message = null;
        }
        if ($message !== null && mb_strlen($message, 'UTF-8') > self::MESSAGE_MAX_LENGTH) {
            throw new ApiException(400, 'validation_error', 'The message must not exceed 500 characters.');
        }

        $signedOut = 0;
        $this->pdo->beginTransaction();
        try {
            $previous = $this->status();
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE system_settings
SET lockdown_enabled = :enabled,
    lockdown_message = :message,
    lockdown_since = CASE
        WHEN NOT CAST(:still_enabled AS boolean) THEN NULL
        WHEN lockdown_enabled THEN lockdown_since
        ELSE NOW()
    END,
    updated_at = NOW()
WHERE id = 1
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare lockdown update.');
            }
            $statement->bindValue(':enabled', $enabled, PDO::PARAM_BOOL);
            $statement->bindValue(':still_enabled', $enabled, PDO::PARAM_BOOL);
            $statement->bindValue(':message', $message, $message === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->execute();

            if ($enabled && $signOutOthers) {
                $signedOut = $this->signOutEveryoneButSuperAdministrators($actor, $message ?? self::DEFAULT_MESSAGE);
            }
            if ($enabled !== $previous['enabled']) {
                // Open tabs show or hide the banner at once; the toast says what happened.
                $shown = $message ?? self::DEFAULT_MESSAGE;
                (new EventRepository($this->pdo))->publish(
                    type: 'global_broadcast',
                    payload: [
                        'message' => $enabled ? 'Maintenance lockdown: ' . $shown : 'Maintenance lockdown has ended.',
                        'lockdown' => ['enabled' => $enabled, 'message' => $enabled ? $shown : null],
                    ],
                    actorUserId: $actor->id,
                    expiresAt: new DateTimeImmutable('+1 hour'),
                );
            }
            if ($enabled !== $previous['enabled'] || $previous['custom_message'] !== $message || $signedOut > 0) {
                $this->audit->log(
                    $actor->id,
                    $enabled ? 'system.lockdown_enabled' : 'system.lockdown_disabled',
                    'system_settings',
                    '1',
                    ['message' => $message, 'signed_out_accounts' => $signedOut],
                    $ipAddress,
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [...$this->status(), 'signed_out' => $signedOut];
    }

    /**
     * Ends every other session at once: their session version moves on, and
     * open tabs are told through the existing forced-logout event.
     */
    private function signOutEveryoneButSuperAdministrators(AuthenticatedUser $actor, string $reason): int
    {
        $statement = $this->pdo->query(<<<'SQL'
UPDATE users u
SET session_version = session_version + 1,
    updated_at = NOW()
WHERE u.account_state = 'active'
  AND NOT EXISTS (
      SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'super_admin'
  )
RETURNING u.id, u.session_version
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to end sessions for lockdown.');
        }

        $events = new EventRepository($this->pdo);
        $count = 0;
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events->publish(
                type: 'forced_logout',
                payload: [
                    'action' => 'maintenance_lockdown',
                    'reason' => $reason,
                    'session_version' => (int) $row['session_version'],
                ],
                targetUserId: (int) $row['id'],
                actorUserId: $actor->id,
                expiresAt: new DateTimeImmutable('+5 minutes'),
            );
            $count++;
        }

        return $count;
    }
}
