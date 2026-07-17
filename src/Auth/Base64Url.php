<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use ChitChat\Http\ApiException;

final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value, string $field = 'value'): string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            throw new ApiException(400, 'invalid_webauthn_payload', $field . ' is not valid base64url data.');
        }
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        if ($decoded === false || !hash_equals(self::encode($decoded), $value)) {
            throw new ApiException(400, 'invalid_webauthn_payload', $field . ' is not valid base64url data.');
        }

        return $decoded;
    }
}
