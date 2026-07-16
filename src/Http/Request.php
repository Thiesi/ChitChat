<?php

declare(strict_types=1);

namespace ChitChat\Http;

use JsonException;

final class Request
{
    public static function requireMethod(string $method): void
    {
        $actual = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($actual !== strtoupper($method)) {
            throw new ApiException(405, 'method_not_allowed', 'Method not allowed.');
        }
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

    public static function clientIp(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? 'unknown' : $ip;
    }
}
