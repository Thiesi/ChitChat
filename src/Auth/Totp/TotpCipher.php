<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Http\ApiException;
use SensitiveParameter;

final class TotpCipher
{
    /** @return array{ciphertext:string,iv:string,tag:string} Raw binary fields. */
    public static function encrypt(
        #[SensitiveParameter] string $seed,
        #[SensitiveParameter] string $key,
        int $userId,
        string $credentialId,
        string $keyId,
    ): array {
        self::requireParameters($key, $userId, $credentialId, $keyId);
        if (strlen($seed) !== TotpProtocol::SEED_BYTES) {
            throw self::unavailable();
        }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt(
            $seed, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag,
            self::aad($userId, $credentialId, $keyId), 16,
        );
        if ($encrypted === false || strlen($encrypted) !== 20 || strlen($tag) !== 16) {
            throw self::unavailable();
        }
        return ['ciphertext' => $encrypted, 'iv' => $iv, 'tag' => $tag];
    }

    public static function decrypt(
        #[SensitiveParameter] string $ciphertext,
        #[SensitiveParameter] string $iv,
        #[SensitiveParameter] string $tag,
        #[SensitiveParameter] string $key,
        int $userId,
        string $credentialId,
        string $keyId,
    ): string {
        self::requireParameters($key, $userId, $credentialId, $keyId);
        // OpenSSL otherwise accepts truncated GCM tags. Require the complete tag.
        if (strlen($ciphertext) !== 20 || strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw self::unavailable();
        }
        $seed = openssl_decrypt(
            $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag,
            self::aad($userId, $credentialId, $keyId),
        );
        if ($seed === false || strlen($seed) !== TotpProtocol::SEED_BYTES) {
            throw self::unavailable();
        }
        return $seed;
    }

    public static function unavailable(): ApiException
    {
        return new ApiException(503, 'totp_unavailable', 'Authenticator verification is unavailable. Use another MFA method or contact the server operator.');
    }

    private static function requireParameters(
        #[SensitiveParameter] string $key,
        int $userId,
        string $credentialId,
        string $keyId,
    ): void {
        if (!extension_loaded('openssl') || strlen($key) !== 32 || $userId < 1
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $credentialId) !== 1
            || preg_match('/\A(?:[A-Za-z0-9_-]{1,32}|db:[a-f0-9]{32})\z/D', $keyId) !== 1) {
            throw self::unavailable();
        }
    }

    private static function aad(int $userId, string $credentialId, string $keyId): string
    {
        return 'chitchat:totp:1|' . $userId . '|' . $credentialId . '|' . $keyId;
    }
}