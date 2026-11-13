<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Config;
use ChitChat\Database;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    /** Tables that keep their rows between tests; setUp resets their contents instead. */
    public const KEPT_TABLES = ['schema_migrations', 'system_settings', 'policy_documents'];

    protected PDO $pdo;
    protected Config $config;

    private static ?string $cleanupSql = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Config::fromEnvironment();
        $this->pdo = Database::connect($this->config);
        $this->pdo->exec("UPDATE policy_documents SET source = 'none', markdown = NULL, url = NULL, version = 0, change_summary = NULL, updated_at = NULL");
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
    lockdown_enabled = FALSE,
    lockdown_message = NULL,
    lockdown_since = NULL,
    registration_rate_limit_max_attempts = NULL,
    registration_rate_limit_window_seconds = NULL,
    registration_min_fill_seconds = NULL,
    registration_proof_of_work_bits = NULL,
    application_name = NULL,
    application_icon_revision = NULL,
    application_icon_preset = NULL,
    application_icon_initial = NULL,
    application_icon_updated_at = NULL,
    application_icon_updated_by = NULL,
    guest_access_enabled = FALSE,
    username_reservation_days = 365,
    backup_max_age_hours = 26,
    maintenance_max_age_hours = NULL,
    sign_in_decoration = 'off',
    updated_at = NOW()
WHERE id = 1
SQL);
        // After the kept tables are reset: system_settings may point at installation_icons.
        $this->pdo->exec(self::cleanupSql($this->pdo));
    }

    /**
     * Empties every table except KEPT_TABLES and restarts every sequence (#163).
     *
     * This replaces `TRUNCATE … RESTART IDENTITY CASCADE`, which costs about a second per test
     * however few rows there are. A plain DELETE of a test's rows takes milliseconds. Tables are
     * deleted children first, in an order read once per process from the foreign keys, so a new
     * table is covered without editing a list. DatabaseCleanupTest guards the result.
     */
    protected static function cleanupSql(PDO $pdo): string
    {
        if (self::$cleanupSql !== null) {
            return self::$cleanupSql;
        }

        $tables = $pdo->query(<<<'SQL'
SELECT c.relname
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
ORDER BY c.relname
SQL)->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_values(array_diff($tables, self::KEPT_TABLES));

        // parent => children that reference it (self-references don't constrain the order).
        $children = array_fill_keys($tables, []);
        $foreignKeys = $pdo->query(<<<'SQL'
SELECT child.relname AS child, parent.relname AS parent
FROM pg_constraint con
JOIN pg_class child ON child.oid = con.conrelid
JOIN pg_class parent ON parent.oid = con.confrelid
JOIN pg_namespace n ON n.oid = child.relnamespace
WHERE con.contype = 'f' AND n.nspname = 'public'
SQL)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreignKeys as $foreignKey) {
            // A kept table's references are cleared by setUp's reset before the cleanup runs.
            if ($foreignKey['child'] === $foreignKey['parent'] || !isset($children[$foreignKey['parent']], $children[$foreignKey['child']])) {
                continue;
            }
            $children[$foreignKey['parent']][] = $foreignKey['child'];
        }

        // Depth-first: a table is deleted only after every table referencing it.
        $order = [];
        $state = [];
        $visit = static function (string $table) use (&$visit, &$order, &$state, $children): void {
            if (($state[$table] ?? null) === 'done') {
                return;
            }
            if (($state[$table] ?? null) === 'visiting') {
                throw new \RuntimeException("Test cleanup: foreign keys form a cycle through {$table}.");
            }
            $state[$table] = 'visiting';
            foreach ($children[$table] as $child) {
                $visit($child);
            }
            $state[$table] = 'done';
            $order[] = $table;
        };
        foreach ($tables as $table) {
            $visit($table);
        }

        $statements = array_map(static fn (string $table): string => 'DELETE FROM "' . str_replace('"', '""', $table) . '"', $order);
        // Every public sequence restarts, as RESTART IDENTITY did for the tables' own ones.
        $statements[] = <<<'SQL'
SELECT setval(s.seqrelid, s.seqstart, false)
FROM pg_sequence s
JOIN pg_class c ON c.oid = s.seqrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
SQL;

        return self::$cleanupSql = "BEGIN;\n" . implode(";\n", $statements) . ";\nCOMMIT";
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

    /**
     * A copy of the test configuration with some settings replaced.
     *
     * @param array<string, mixed> $overrides constructor argument names to values
     */
    protected function configWith(array $overrides): Config
    {
        $arguments = [];
        $constructor = (new \ReflectionClass(Config::class))->getConstructor();
        foreach ($constructor === null ? [] : $constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = array_key_exists($name, $overrides) ? $overrides[$name] : $this->config->{$name};
        }

        return new Config(...$arguments);
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
