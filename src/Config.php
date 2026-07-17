<?php

declare(strict_types=1);
namespace ChitChat;

use ChitChat\Http\RateLimitPolicy;
use ChitChat\Http\RateLimitPolicySet;
use InvalidArgumentException;

final readonly class Config
{
    public RateLimitPolicySet $rateLimits;

    /** @param 'Lax'|'Strict'|'None' $sessionCookieSameSite */
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
        public string $sessionName,
        public bool $sessionCookieSecure,
        public string $sessionCookieSameSite,
        public int $loginMaxAttempts,
        public int $loginLockMinutes,
        public int $presenceLeaseSeconds,
        public int $inactivityWarningSeconds,
        public string $attachmentStoragePath,
        public int $attachmentMaxBytes,
        public bool $directMessageInspectionEnabled = true,
        public string $directMessageInspectionRole = 'super_admin',
        public bool $messageRevisionReviewEnabled = false,
        public string $messageRevisionReviewRole = 'super_admin',
        public int $sseConnectionLeaseSeconds = 40,
        public string $metricsBearerToken = '',
        public int $maintenanceMaxAgeHours = 26,
        public int $privilegedStepUpMaxAgeSeconds = 600,
        ?RateLimitPolicySet $rateLimits = null,
    ) {
        if ($this->databasePort < 1 || $this->databasePort > 65535) {
            throw new InvalidArgumentException('DB_PORT must be between 1 and 65535.');
        }

        if ($this->loginMaxAttempts < 1) {
            throw new InvalidArgumentException('LOGIN_MAX_ATTEMPTS must be at least 1.');
        }

        if ($this->loginLockMinutes < 1) {
            throw new InvalidArgumentException('LOGIN_LOCK_MINUTES must be at least 1.');
        }

        if ($this->presenceLeaseSeconds < 30 || $this->presenceLeaseSeconds > 300) {
            throw new InvalidArgumentException('PRESENCE_LEASE_SECONDS must be between 30 and 300.');
        }

        if ($this->inactivityWarningSeconds < 10 || $this->inactivityWarningSeconds > 3600) {
            throw new InvalidArgumentException('INACTIVITY_WARNING_SECONDS must be between 10 and 3600.');
        }

        if ($this->attachmentMaxBytes < 1024 || $this->attachmentMaxBytes > 104_857_600) {
            throw new InvalidArgumentException('ATTACHMENT_MAX_BYTES must be between 1024 and 104857600.');
        }

        if ($this->sseConnectionLeaseSeconds < 20 || $this->sseConnectionLeaseSeconds > 300) {
            throw new InvalidArgumentException('SSE_CONNECTION_LEASE_SECONDS must be between 20 and 300.');
        }

        if ($this->metricsBearerToken !== '' && strlen($this->metricsBearerToken) < 24) {
            throw new InvalidArgumentException('METRICS_BEARER_TOKEN must contain at least 24 characters when enabled.');
        }

        if ($this->maintenanceMaxAgeHours < 1 || $this->maintenanceMaxAgeHours > 720) {
            throw new InvalidArgumentException('MAINTENANCE_MAX_AGE_HOURS must be between 1 and 720.');
        }

        if ($this->privilegedStepUpMaxAgeSeconds < 60 || $this->privilegedStepUpMaxAgeSeconds > 3600) {
            throw new InvalidArgumentException('PRIVILEGED_STEP_UP_MAX_AGE_SECONDS must be between 60 and 3600.');
        }

        if (!self::isAbsolutePath($this->attachmentStoragePath)) {
            throw new InvalidArgumentException('ATTACHMENT_STORAGE_PATH must be an absolute path.');
        }

        $storagePath = self::normalizePath($this->attachmentStoragePath);
        $publicPath = self::normalizePath(dirname(__DIR__) . '/public');
        if ($storagePath === $publicPath || str_starts_with($storagePath, $publicPath . '/')) {
            throw new InvalidArgumentException('ATTACHMENT_STORAGE_PATH must be outside the public web root.');
        }

        if (!in_array($this->directMessageInspectionRole, ['super_admin', 'admin'], true)) {
            throw new InvalidArgumentException('DM_ADMIN_INSPECTION_ROLE must be super_admin or admin.');
        }

        if (!in_array($this->messageRevisionReviewRole, ['super_admin', 'admin'], true)) {
            throw new InvalidArgumentException('MESSAGE_REVISION_REVIEW_ROLE must be super_admin or admin.');
        }

        if ($this->sessionCookieSameSite === 'None' && !$this->sessionCookieSecure) {
            throw new InvalidArgumentException('SameSite=None requires a secure session cookie.');
        }

        foreach ([$this->databaseHost, $this->databaseName, $this->databaseUser, $this->sessionName] as $required) {
            if ($required === '') {
                throw new InvalidArgumentException('Required application configuration is missing.');
            }
        }

        $this->rateLimits = $rateLimits ?? RateLimitPolicySet::defaults(
            $this->loginMaxAttempts,
            $this->loginLockMinutes * 60,
        );
    }

    public static function fromEnvironment(): self
    {
        $loginMaxAttempts = self::envInt('LOGIN_MAX_ATTEMPTS', 10);
        $loginLockMinutes = self::envInt('LOGIN_LOCK_MINUTES', 15);

        return new self(
            environment: self::env('APP_ENV', 'production'),
            debug: self::envBool('APP_DEBUG', false),
            applicationName: self::env('APP_NAME', 'ChitChat'),
            applicationVersion: self::env('APP_VERSION', '1.1.0'),
            databaseHost: self::env('DB_HOST', '127.0.0.1'),
            databasePort: self::envInt('DB_PORT', 5432),
            databaseName: self::env('DB_NAME', 'chitchat'),
            databaseUser: self::env('DB_USER', 'chitchat'),
            databasePassword: self::env('DB_PASSWORD', ''),
            databaseSslMode: self::env('DB_SSLMODE', 'prefer'),
            sessionName: self::env('SESSION_NAME', 'CHITCHATSESSID'),
            sessionCookieSecure: self::envBool('SESSION_COOKIE_SECURE', true),
            sessionCookieSameSite: self::envSameSite('SESSION_COOKIE_SAMESITE', 'Lax'),
            loginMaxAttempts: $loginMaxAttempts,
            loginLockMinutes: $loginLockMinutes,
            presenceLeaseSeconds: self::envInt('PRESENCE_LEASE_SECONDS', 45),
            inactivityWarningSeconds: self::envInt('INACTIVITY_WARNING_SECONDS', 60),
            attachmentStoragePath: self::env('ATTACHMENT_STORAGE_PATH', dirname(__DIR__) . '/var/uploads'),
            attachmentMaxBytes: self::envInt('ATTACHMENT_MAX_BYTES', 10_485_760),
            directMessageInspectionEnabled: self::envBool('DM_ADMIN_INSPECTION_ENABLED', true),
            directMessageInspectionRole: self::envAdministrativeRole('DM_ADMIN_INSPECTION_ROLE', 'super_admin'),
            messageRevisionReviewEnabled: self::envBool('MESSAGE_REVISION_REVIEW_ENABLED', false),
            messageRevisionReviewRole: self::envAdministrativeRole('MESSAGE_REVISION_REVIEW_ROLE', 'super_admin'),
            sseConnectionLeaseSeconds: self::envInt('SSE_CONNECTION_LEASE_SECONDS', 40),
            metricsBearerToken: self::env('METRICS_BEARER_TOKEN', ''),
            maintenanceMaxAgeHours: self::envInt('MAINTENANCE_MAX_AGE_HOURS', 26),
            privilegedStepUpMaxAgeSeconds: self::envInt('PRIVILEGED_STEP_UP_MAX_AGE_SECONDS', 600),
            rateLimits: RateLimitPolicySet::fromEnvironment(
                $loginMaxAttempts,
                $loginLockMinutes * 60,
            ),
        );
    }

    public function rateLimitPolicy(string $name): RateLimitPolicy
    {
        return $this->rateLimits->get($name);
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

    /** @return 'Lax'|'Strict'|'None' */
    private static function envSameSite(string $name, string $default): string
    {
        $value = ucfirst(strtolower(self::env($name, $default)));
        if (!in_array($value, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException($name . ' must be Lax, Strict, or None.');
        }

        return $value;
    }

    /** @return 'super_admin'|'admin' */
    private static function envAdministrativeRole(string $name, string $default): string
    {
        $value = strtolower(self::env($name, $default));
        if (!in_array($value, ['super_admin', 'admin'], true)) {
            throw new InvalidArgumentException($name . ' must be super_admin or admin.');
        }

        return $value;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
