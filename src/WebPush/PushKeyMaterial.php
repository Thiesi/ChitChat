<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Http\ApiException;

final readonly class PushKeyMaterial
{
    private function __construct(public string $publicKey, #[\SensitiveParameter] public string $auth)
    {
    }

    public static function fromInput(#[\SensitiveParameter] string $publicKey, #[\SensitiveParameter] string $auth): self
    {
        $point = self::decode($publicKey, 65);
        $secret = self::decode($auth, 16);
        if ($point[0] !== "\x04") {
            throw self::invalid();
        }
        // ASN.1 SubjectPublicKeyInfo for the named P-256 curve and an uncompressed point.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        while (openssl_error_string() !== false) {
            // Do not leave attacker-supplied key parsing diagnostics in a later operation's error queue.
        }
        if ($key === false) {
            throw self::invalid();
        }
        return new self(self::encode($point), self::encode($secret));
    }

    private static function decode(#[\SensitiveParameter] string $value, int $bytes): string
    {
        $maximum = (int) (4 * ceil($bytes / 3));
        if ($value === '' || strlen($value) > $maximum
            || preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/D', $value) !== 1
            || (str_contains($value, '=') && strlen($value) % 4 !== 0)) {
            throw self::invalid();
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || strlen($decoded) !== $bytes || self::encode($decoded) !== rtrim($value, '=')) {
            throw self::invalid();
        }
        return $decoded;
    }

    private static function encode(#[\SensitiveParameter] string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function invalid(): ApiException
    {
        return new ApiException(400, 'validation_error', 'Push keys must be a valid uncompressed P-256 public key and a 16-byte auth secret in base64url.');
    }
}
