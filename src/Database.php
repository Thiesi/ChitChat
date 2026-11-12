<?php

declare(strict_types=1);

namespace ChitChat;

use PDO;
use InvalidArgumentException;

final class Database
{
    /**
     * Web requests end a session left idle inside a transaction after this long (#286), so no
     * ChitChat web session can hold back settled stream positions. Only idle time inside an
     * open transaction counts, never a running statement. CLI tools (migrations, the push
     * dispatcher, backups, maintenance) keep the server's setting.
     */
    public const WEB_IDLE_IN_TRANSACTION_TIMEOUT_MS = 60_000;

    /** @param ?bool $web apply the web session settings; by default when not running from the CLI */
    public static function connect(Config $config, ?int $connectTimeoutSeconds = null, ?bool $web = null): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $config->databaseHost,
            $config->databasePort,
            $config->databaseName,
            $config->databaseSslMode,
        );

        if ($connectTimeoutSeconds !== null) {
            if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > 30) {
                throw new InvalidArgumentException('Database connect timeout must be between 1 and 30 seconds.');
            }
            $dsn .= ';connect_timeout=' . $connectTimeoutSeconds;
        }

        $pdo = new PDO(
            $dsn,
            $config->databaseUser,
            $config->databasePassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
        // A SET rather than a DSN `options=`, which would replace an operator's PGOPTIONS.
        if ($web ?? PHP_SAPI !== 'cli') {
            $pdo->exec('SET idle_in_transaction_session_timeout = ' . self::WEB_IDLE_IN_TRANSACTION_TIMEOUT_MS);
        }

        return $pdo;
    }
}
