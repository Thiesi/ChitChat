<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Totp\TotpCipher;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class TotpCipherTest extends TestCase
{
    public function testRoundTripFreshIvAndEveryAuthenticatedContextBoundary(): void
    {
        $seed = random_bytes(20);
        $key = random_bytes(32);
        $id = str_repeat('A', 43);
        $sealed = TotpCipher::encrypt($seed, $key, 23, $id, 'old');
        self::assertSame(20, strlen($sealed['ciphertext']));
        self::assertSame(12, strlen($sealed['iv']));
        self::assertSame(16, strlen($sealed['tag']));
        self::assertNotSame($seed, $sealed['ciphertext']);
        self::assertNotSame($sealed, TotpCipher::encrypt($seed, $key, 23, $id, 'old'));
        self::assertSame($seed, TotpCipher::decrypt(...[...array_values($sealed), $key, 23, $id, 'old']));
        $newKey = random_bytes(32);
        $rewrapped = TotpCipher::encrypt($seed, $newKey, 23, $id, 'new');
        self::assertSame($seed, TotpCipher::decrypt(...[...array_values($rewrapped), $newKey, 23, $id, 'new']));
        $args = [...array_values($sealed), $key, 23, $id, 'old'];
        foreach ([
            [0, str_repeat("\0", 20)], [1, str_repeat("\0", 12)], [2, str_repeat("\0", 16)],
            [0, ''], [1, ''], [2, substr($sealed['tag'], 0, 8)],
            [3, random_bytes(32)], [3, substr($key, 0, 31)], [3, $key . "\0"],
            [4, 24], [5, str_repeat('B', 43)], [6, 'new'],
        ] as [$offset, $replacement]) {
            $tampered = $args;
            $tampered[$offset] = $replacement;
            try {
                TotpCipher::decrypt(...$tampered);
                self::fail('Tampered seed or context decrypted.');
            } catch (ApiException $error) {
                self::assertSame(503, $error->status);
                self::assertSame('totp_unavailable', $error->errorCode);
                self::assertStringNotContainsString(base64_encode($key), $error->getMessage());
            }
        }
    }
}