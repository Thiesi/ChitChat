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
            'TRUNCATE TABLE room_presence, realtime_events, room_messages, room_invitations, room_members, rooms, audit_log, user_bans, login_attempts, user_roles, users RESTART IDENTITY CASCADE',
        );
        $this->pdo->exec('UPDATE system_settings SET registration_enabled = TRUE WHERE id = 1');
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
        );
    }
}
