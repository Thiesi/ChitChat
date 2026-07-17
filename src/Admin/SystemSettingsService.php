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

    /**
     * @return array{
     *   registration_enabled:bool,
     *   room_message_retention_days:int,
     *   direct_message_retention_days:int,
     *   audit_retention_days:int,
     *   deleted_attachment_retention_days:int,
     *   orphan_attachment_grace_hours:int,
     *   realtime_event_retention_hours:int,
     *   login_attempt_retention_days:int,
     *   updated_at:string
     * }
     */
    public function get(AuthenticatedUser $actor): array
    {
        $this->requireSuperAdministrator($actor);

        return $this->load();
    }

    /**
     * @return array{
     *   registration_enabled:bool,
     *   room_message_retention_days:int,
     *   direct_message_retention_days:int,
     *   audit_retention_days:int,
     *   deleted_attachment_retention_days:int,
     *   orphan_attachment_grace_hours:int,
     *   realtime_event_retention_hours:int,
     *   login_attempt_retention_days:int,
     *   updated_at:string
     * }
     */
    public function update(
        AuthenticatedUser $actor,
        bool $registrationEnabled,
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

            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE system_settings
SET registration_enabled = :registration_enabled,
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

    /**
     * @return array{
     *   registration_enabled:bool,
     *   room_message_retention_days:int,
     *   direct_message_retention_days:int,
     *   audit_retention_days:int,
     *   deleted_attachment_retention_days:int,
     *   orphan_attachment_grace_hours:int,
     *   realtime_event_retention_hours:int,
     *   login_attempt_retention_days:int,
     *   updated_at:string
     * }
     */
    private function load(): array
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT registration_enabled::int,
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

    private function requireSuperAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'System settings require Super-Administrator access.');
        }
    }

    private function validateDays(string $name, int $value, bool $allowZero): void
    {
        $minimum = $allowZero ? 0 : 1;
        $this->validateRange($name, $value, $minimum, 3650);
    }

    private function validateRange(string $name, int $value, int $minimum, int $maximum): void
    {
        if ($value < $minimum || $value > $maximum) {
            throw new ApiException(
                400,
                'validation_error',
                sprintf('%s must be between %d and %d.', $name, $minimum, $maximum),
            );
        }
    }
}
