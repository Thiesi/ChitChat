<?php

declare(strict_types=1);

namespace ChitChat\Auth;

/**
 * Builds a PEM public key from a raw RSA modulus and exponent, as passkeys
 * (COSE) and sign-in providers (JWK) publish them, for openssl_verify().
 */
final class RsaPublicKey
{
    public static function pem(string $modulus, string $exponent): string
    {
        $rsa = self::sequence(self::integer($modulus) . self::integer($exponent));
        // AlgorithmIdentifier: rsaEncryption with NULL parameters.
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $der = self::sequence($algorithm . self::bitString($rsa));

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private static function sequence(string $value): string
    {
        return "\x30" . self::length(strlen($value)) . $value;
    }

    private static function bitString(string $value): string
    {
        $value = "\x00" . $value;
        return "\x03" . self::length(strlen($value)) . $value;
    }

    private static function integer(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . self::length(strlen($value)) . $value;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}
