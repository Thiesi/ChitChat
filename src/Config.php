<?php

declare(strict_types=1);

namespace ChitChat;

use InvalidArgumentException;

final readonly class Config
{
    public function __construct(
        public string $environment,
        public bool $debug,
        public string $applicationName,
        public string $applicationVersion,
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUser,
        public string $databasePassword,
        public string $databaseSslMode,
    ) {
        if ($this->databasePort < 1 || $this->databasePort > 65535) {
            throw new InvalidArgumentException('DB_PORT must be between 1 and 65535.');
        }

        foreach ([$this->databaseHost, $this->databaseName, $this->databaseUser] as $required) {
            if ($required === '') {
                throw new InvalidArgumentException('Required database configuration is missing.');
            }
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            environment: self::env('APP_ENV', 'production'),
            debug: self::envBool('APP_DEBUG', false),
            applicationName: self::env('APP_NAME', 'ChitChat'),
            applicationVersion: self::env('APP_VERSION', '0.1.0-dev'),
            databaseHost: self::env('DB_HOST', '127.0.0.1'),
            databasePort: self::envInt('DB_PORT', 5432),
            databaseName: self::env('DB_NAME', 'chitchat'),
            databaseUser: self::env('DB_USER', 'chitchat'),
            databasePassword: self::env('DB_PASSWORD', ''),
            databaseSslMode: self::env('DB_SSLMODE', 'prefer'),
        );
    }

    public static function loadEnvFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);
        return $value === false ? $default : trim($value);
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($name . ' must be an integer.');
        }

        return (int) $value;
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => throw new InvalidArgumentException($name . ' must be a boolean value.'),
        };
    }
}
