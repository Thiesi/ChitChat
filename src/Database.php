<?php

declare(strict_types=1);

namespace ChitChat;

use PDO;
use InvalidArgumentException;

final class Database
{
    public static function connect(Config $config, ?int $connectTimeoutSeconds = null): PDO
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

        return new PDO(
            $dsn,
            $config->databaseUser,
            $config->databasePassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
