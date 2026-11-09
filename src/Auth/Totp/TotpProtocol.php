<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Http\ApiException;
use InvalidArgumentException;
use SensitiveParameter;

/** RFC 6238 / RFC 4226: SHA-1, six digits, 30 seconds, one step of drift. */
final class TotpProtocol
{
    public const PERIOD_SECONDS = 30;
    public const SEED_BYTES = 20;

    public static function normalizeCode(#[SensitiveParameter] string $input): string
    {
        if (strlen($input) > 64) {
            throw new ApiException(400, 'validation_error', 'Enter a six-digit authenticator code.');
        }
        $code = str_replace([' ', '-', '.'], '', $input);
        if (preg_match('/\A[0-9]{6}\z/D', $code) !== 1) {
            throw new ApiException(400, 'validation_error', 'Enter a six-digit authenticator code.');
        }
        return $code;
    }

    public static function codeAtStep(#[SensitiveParameter] string $seed, int $step): string
    {
        if (strlen($seed) !== self::SEED_BYTES || $step < 0) {
            throw new InvalidArgumentException('Invalid authenticator parameters.');
        }
        $counter = pack('N2', intdiv($step, 4_294_967_296), $step & 0xffffffff);
        $mac = hash_hmac('sha1', $counter, $seed, true);
        $offset = ord($mac[19]) & 15;
        $number = ((ord($mac[$offset]) & 127) << 24)
            | (ord($mac[$offset + 1]) << 16)
            | (ord($mac[$offset + 2]) << 8)
            | ord($mac[$offset + 3]);
        return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Highest allowed matching step. The caller locks and persists it with its effect. */
    public static function matchingStep(
        #[SensitiveParameter] string $seed,
        #[SensitiveParameter] string $input,
        int $unixTime,
        int $lastAcceptedStep,
    ): ?int {
        $code = self::normalizeCode($input);
        if ($unixTime < 0 || $lastAcceptedStep < -1) {
            throw new InvalidArgumentException('Invalid authenticator time.');
        }
        $current = intdiv($unixTime, self::PERIOD_SECONDS);
        $matched = null;
        // Always compare the complete window; do not stop at the first match.
        for ($step = max(0, $current - 1); $step <= $current + 1; ++$step) {
            if (hash_equals(self::codeAtStep($seed, $step), $code) && $step > $lastAcceptedStep) {
                $matched = $step;
            }
        }
        return $matched;
    }

    public static function base32(#[SensitiveParameter] string $seed): string
    {
        if (strlen($seed) !== self::SEED_BYTES) {
            throw new InvalidArgumentException('Invalid authenticator seed.');
        }
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = 0;
        $buffer = 0;
        $encoded = '';
        foreach (str_split($seed) as $byte) {
            $buffer = ($buffer << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        return $encoded;
    }

    public static function provisioningUri(
        #[SensitiveParameter] string $seed,
        string $installationName,
        string $username,
    ): string {
        // A colon in the issuer/account label is ambiguous even when percent-encoded.
        $issuer = str_replace(':', ' ', $installationName);
        $account = str_replace(':', ' ', $username);
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . self::base32($seed)
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}