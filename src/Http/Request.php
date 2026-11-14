<?php

declare(strict_types=1);

namespace ChitChat\Http;

use JsonException;

final class Request
{
    /**
     * One of the given methods, or 405 with an Allow header naming them (RFC 9110). Most
     * endpoints allow one; the downloads allow GET and HEAD (#357).
     */
    public static function requireMethod(string $method, string ...$others): void
    {
        $allowed = array_map(strtoupper(...), [$method, ...$others]);
        $actual = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($actual, $allowed, true)) {
            return;
        }
        if (!headers_sent()) {
            header('Allow: ' . implode(', ', $allowed));
        }
        throw new ApiException(405, 'method_not_allowed', 'Method not allowed.');
    }

    /**
     * A HEAD request: the same status and headers as GET, and no body. Exactly "HEAD", as
     * methods are case-sensitive and PHP itself only drops the body for that spelling.
     */
    public static function isHead(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
    }

    /** @return array<string, mixed> */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new JsonException('The JSON request body must be an object.');
        }

        return get_object_vars($decoded);
    }

    /** @param array<string, mixed> $payload */
    public static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            throw new ApiException(400, 'validation_error', $key . ' must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    public static function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new ApiException(400, 'validation_error', $key . ' must be a string or null.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    public static function integer(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (!is_int($value)) {
            throw new ApiException(400, 'validation_error', $key . ' must be an integer.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    public static function optionalInteger(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_int($value)) {
            throw new ApiException(400, 'validation_error', $key . ' must be an integer or null.');
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    public static function boolean(array $payload, string $key): bool
    {
        $value = $payload[$key] ?? null;
        if (!is_bool($value)) {
            throw new ApiException(400, 'validation_error', $key . ' must be a boolean.');
        }

        return $value;
    }

    public static function queryString(string $key): string
    {
        $value = $_GET[$key] ?? null;
        if (!is_string($value)) {
            throw new ApiException(400, 'validation_error', $key . ' must be a string query parameter.');
        }

        return $value;
    }

    public static function optionalQueryString(string $key): ?string
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }

        return self::queryString($key);
    }

    public static function queryInteger(string $key): int
    {
        $value = $_GET[$key] ?? null;
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new ApiException(400, 'validation_error', $key . ' must be an integer query parameter.');
        }

        return (int) $value;
    }

    public static function optionalQueryInteger(string $key): ?int
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }

        return self::queryInteger($key);
    }

    public static function csrfHeader(): string
    {
        return trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    }

    /**
     * Deliberately reads REMOTE_ADDR only. ChitChat does not trust
     * X-Forwarded-For or similar headers, since doing so without a
     * configured trusted-proxy allowlist (which does not exist here) would
     * let any client spoof its own address. This requires the deployment's
     * Nginx to terminate the client's TCP connection directly — see
     * docs/operations/nginx-php-fpm.md.
     */
    public static function clientIp(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? 'unknown' : $ip;
    }
}
