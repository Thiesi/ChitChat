<?php

declare(strict_types=1);
namespace ChitChat\Admin;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use PDO;
use RuntimeException;
use Throwable;

final class SystemSettingsService
{
    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->audit = new AuditLogger($pdo);
    }

    /** @return array<string, bool|int|string> */
    public function get(AuthenticatedUser $actor): array
    {
        $this->requireSuperAdministrator($actor);
        return $this->load();
    }

    /** @return array<string, bool|int|string> */
    public function update(
        AuthenticatedUser $actor,
        bool $registrationEnabled,
        bool $mfaRequiredForAdminRoles,
        int $roomMessageRetentionDays,
        int $directMessageRetentionDays,
        int $auditRetentionDays,
        int $deletedAttachmentRetentionDays,
        int $orphanAttachmentGraceHours,
        int $realtimeEventRetentionHours,
        int $loginAttemptRetentionDays,
        string $ipAddress,
    ): array {
        $this->requireSuperAdministrator($actor);
        $this->validateDays('room_message_retention_days', $roomMessageRetentionDays, true);
        $this->validateDays('direct_message_retention_days', $directMessageRetentionDays, true);
        $this->validateDays('audit_retention_days', $auditRetentionDays, true);
        $this->validateDays('deleted_attachment_retention_days', $deletedAttachmentRetentionDays, true);
        $this->validateRange('orphan_attachment_grace_hours', $orphanAttachmentGraceHours, 1, 720);
        $this->validateRange('realtime_event_retention_hours', $realtimeEventRetentionHours, 1, 8760);
        $this->validateRange('login_attempt_retention_days', $loginAttemptRetentionDays, 1, 3650);

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
            if ($lock === false || $lock->fetchColumn() === false) {
                throw new RuntimeException('Unable to lock system settings.');
            }
            $old = $this->load();
            if ($mfaRequiredForAdminRoles && !$old['mfa_required_for_admin_roles']) {
                $this->assertEveryAdministratorHasMfa();
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE system_settings
SET registration_enabled = :registration_enabled,
    mfa_required_for_admin_roles = :mfa_required_for_admin_roles,
    room_message_retention_days = :room_message_retention_days,
    direct_message_retention_days = :direct_message_retention_days,
    audit_retention_days = :audit_retention_days,
    deleted_attachment_retention_days = :deleted_attachment_retention_days,
    orphan_attachment_grace_hours = :orphan_attachment_grace_hours,
    realtime_event_retention_hours = :realtime_event_retention_hours,
    login_attempt_retention_days = :login_attempt_retention_days,
    updated_at = NOW()
WHERE id = 1
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare system-settings update.');
            }
            $statement->bindValue(':registration_enabled', $registrationEnabled, PDO::PARAM_BOOL);
            $statement->bindValue(':mfa_required_for_admin_roles', $mfaRequiredForAdminRoles, PDO::PARAM_BOOL);
            $statement->bindValue(':room_message_retention_days', $roomMessageRetentionDays, PDO::PARAM_INT);
            $statement->bindValue(':direct_message_retention_days', $directMessageRetentionDays, PDO::PARAM_INT);
            $statement->bindValue(':audit_retention_days', $auditRetentionDays, PDO::PARAM_INT);
            $statement->bindValue(':deleted_attachment_retention_days', $deletedAttachmentRetentionDays, PDO::PARAM_INT);
            $statement->bindValue(':orphan_attachment_grace_hours', $orphanAttachmentGraceHours, PDO::PARAM_INT);
            $statement->bindValue(':realtime_event_retention_hours', $realtimeEventRetentionHours, PDO::PARAM_INT);
            $statement->bindValue(':login_attempt_retention_days', $loginAttemptRetentionDays, PDO::PARAM_INT);
            $statement->execute();
            $new = $this->load();
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'system.settings_updated',
                subjectType: 'system_settings',
                subjectId: '1',
                metadata: ['old' => $old, 'new' => $new],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();
            return $new;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, bool|int|string> */
    private function load(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT registration_enabled::int,
       mfa_required_for_admin_roles::int,
       room_message_retention_days,
       direct_message_retention_days,
       audit_retention_days,
       deleted_attachment_retention_days,
       orphan_attachment_grace_hours,
       realtime_event_retention_hours,
       login_attempt_retention_days,
       updated_at
FROM system_settings
WHERE id = 1
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to query system settings.');
        }
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('System settings are missing.');
        }
        return [
            'registration_enabled' => (int) $row['registration_enabled'] === 1,
            'mfa_required_for_admin_roles' => (int) $row['mfa_required_for_admin_roles'] === 1,
            'room_message_retention_days' => (int) $row['room_message_retention_days'],
            'direct_message_retention_days' => (int) $row['direct_message_retention_days'],
            'audit_retention_days' => (int) $row['audit_retention_days'],
            'deleted_attachment_retention_days' => (int) $row['deleted_attachment_retention_days'],
            'orphan_attachment_grace_hours' => (int) $row['orphan_attachment_grace_hours'],
            'realtime_event_retention_hours' => (int) $row['realtime_event_retention_hours'],
            'login_attempt_retention_days' => (int) $row['login_attempt_retention_days'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    private function assertEveryAdministratorHasMfa(): void
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT COUNT(DISTINCT u.id)
FROM users u
JOIN user_roles ur ON ur.user_id = u.id
WHERE u.account_state = 'active'
  AND ur.role IN ('super_admin', 'admin', 'chat_admin', 'global_moderator')
  AND NOT (
      u.mfa_enabled_at IS NOT NULL
      AND EXISTS (SELECT 1 FROM webauthn_credentials wc WHERE wc.user_id = u.id)
      AND EXISTS (SELECT 1 FROM mfa_recovery_codes rc WHERE rc.user_id = u.id AND rc.used_at IS NULL)
  )
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to validate administrative MFA enrollment.');
        }
        $missing = (int) $statement->fetchColumn();
        if ($missing > 0) {
            throw new ApiException(
                409,
                'administrators_missing_mfa',
                sprintf('%d active administrative account(s) must enroll a passkey and retain a recovery code before this policy can be enabled.', $missing),
            );
        }
    }

    private function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'System settings require Super-Administrator access.');
        }
    }

    private function validateDays(string $name, int $value, bool $allowZero): void
    {
        $this->validateRange($name, $value, $allowZero ? 0 : 1, 3650);
    }

    private function validateRange(string $name, int $value, int $minimum, int $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new ApiException(400, 'validation_error', sprintf('%s must be between %d and %d.', $name, $minimum, $maximum));
        }
    }
}
