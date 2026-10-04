<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Config;
use ChitChat\Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected Config $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Config::fromEnvironment();
        $this->pdo = Database::connect($this->config);
        $this->pdo->exec(
            'TRUNCATE TABLE account_notifications, maintenance_runs, sse_connections, rate_limit_counters, request_rate_limits, direct_message_attachments, direct_messages, attachments, room_presence, realtime_events, room_messages, room_invitations, room_members, rooms, audit_log, user_bans, login_attempts, account_closures, mfa_recovery_codes, webauthn_credentials, user_roles, users RESTART IDENTITY CASCADE',
        );
        $this->pdo->exec(<<<'SQL'
UPDATE system_settings
SET registration_enabled = TRUE,
    mfa_required_for_admin_roles = FALSE,
    room_message_retention_days = 0,
    direct_message_retention_days = 0,
    audit_retention_days = 0,
    deleted_attachment_retention_days = 30,
    orphan_attachment_grace_hours = 24,
    realtime_event_retention_hours = 168,
    login_attempt_retention_days = 30,
    deleted_room_grace_days = 30,
    registration_rate_limit_max_attempts = NULL,
    registration_rate_limit_window_seconds = NULL,
    registration_min_fill_seconds = NULL,
    registration_proof_of_work_bits = NULL,
    application_name = NULL,
    updated_at = NOW()
WHERE id = 1
SQL);
    }

    /**
     * Drops the rooms_changed signals that room setup emits, so a test can
     * assert exactly the events it is about.
     *
     * @param list<\ChitChat\Realtime\RealtimeEvent> $events
     * @return list<\ChitChat\Realtime\RealtimeEvent>
     */
    protected static function withoutRoomListSignals(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn ($event): bool => $event->type !== 'rooms_changed',
        ));
    }

    protected function configWithThrottle(int $attempts, int $minutes = 15): Config
    {
        return new Config(
            environment: $this->config->environment,
            debug: $this->config->debug,
            applicationName: $this->config->applicationName,
            applicationVersion: $this->config->applicationVersion,
            databaseHost: $this->config->databaseHost,
            databasePort: $this->config->databasePort,
            databaseName: $this->config->databaseName,
            databaseUser: $this->config->databaseUser,
            databasePassword: $this->config->databasePassword,
            databaseSslMode: $this->config->databaseSslMode,
            sessionName: $this->config->sessionName,
            sessionCookieSecure: $this->config->sessionCookieSecure,
            sessionCookieSameSite: $this->config->sessionCookieSameSite,
            loginMaxAttempts: $attempts,
            loginLockMinutes: $minutes,
            presenceLeaseSeconds: $this->config->presenceLeaseSeconds,
            inactivityWarningSeconds: $this->config->inactivityWarningSeconds,
            attachmentStoragePath: $this->config->attachmentStoragePath,
            attachmentMaxBytes: $this->config->attachmentMaxBytes,
            directMessageInspectionEnabled: $this->config->directMessageInspectionEnabled,
            directMessageInspectionRole: $this->config->directMessageInspectionRole,
            messageRevisionReviewEnabled: $this->config->messageRevisionReviewEnabled,
            messageRevisionReviewRole: $this->config->messageRevisionReviewRole,
            sseConnectionLeaseSeconds: $this->config->sseConnectionLeaseSeconds,
            metricsBearerToken: $this->config->metricsBearerToken,
            maintenanceMaxAgeHours: $this->config->maintenanceMaxAgeHours,
            privilegedStepUpMaxAgeSeconds: $this->config->privilegedStepUpMaxAgeSeconds,
            webauthnRpId: $this->config->webauthnRpId,
            webauthnOrigin: $this->config->webauthnOrigin,
            webauthnChallengeTtlSeconds: $this->config->webauthnChallengeTtlSeconds,
            mfaPendingLoginTtlSeconds: $this->config->mfaPendingLoginTtlSeconds,
        );
    }
}
