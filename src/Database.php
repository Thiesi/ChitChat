<?php

declare(strict_types=1);

namespace ChitChat;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $config->databaseHost,
            $config->databasePort,
            $config->databaseName,
            $config->databaseSslMode,
        );

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
