<?php

declare(strict_types=1);

namespace ChitChat\Auth;

/** 120 random bits, distinct from the hexadecimal MFA recovery-code format. */
final class PasswordRecoveryKey
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (unpack('C*', random_bytes(15)) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        return implode('-', str_split($encoded, 4));
    }

    public static function normalize(#[\SensitiveParameter] string $input): ?string
    {
        if (strlen($input) > 256) {
            return null;
        }
        $value = preg_replace('/[\s-]+/u', '', $input);
        if ($value === null) {
            return null;
        }
        $value = strtr(strtoupper($value), ['O' => '0', 'I' => '1', 'L' => '1']);
        return preg_match('/\A[0-9A-HJKMNP-TV-Z]{24}\z/D', $value) === 1 ? $value : null;
    }

    public static function hash(#[\SensitiveParameter] string $key): ?string
    {
        $normalized = self::normalize($key);
        return $normalized === null ? null : hash('sha256', $normalized);
    }
}
