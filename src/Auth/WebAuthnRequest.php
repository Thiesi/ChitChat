<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use ChitChat\Http\ApiException;
use stdClass;

final class WebAuthnRequest
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function credential(array $payload): array
    {
        $value = $payload['credential'] ?? null;
        $normalized = self::normalize($value);
        if (!is_array($normalized)) {
            throw new ApiException(400, 'validation_error', 'credential must be an object.');
        }

        return $normalized;
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::normalize($item);
        }

        return $result;
    }
}
