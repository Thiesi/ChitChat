<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Totp\TotpKeyRing;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TotpKeyRingTest extends TestCase
{
    public function testServerKeysAreStrictBoundedAndDiagnosticsDoNotExposeThem(): void
    {
        $key = random_bytes(32);
        $old = random_bytes(32);
        $json = json_encode(['new' => base64_encode($key), 'old' => base64_encode($old)], JSON_THROW_ON_ERROR);
        $ring = TotpKeyRing::fromJson($json, 'new');
        self::assertTrue($ring->configured());
        self::assertSame('new', $ring->activeKeyId);
        self::assertSame($key, $ring->key('new'));
        self::assertSame($old, $ring->key('old'));
        self::assertNull($ring->key('unknown'));
        ob_start();
        var_dump($ring);
        $dump = (string) ob_get_clean();
        self::assertStringNotContainsString($key, $dump);
        self::assertStringNotContainsString(base64_encode($key), $dump);
        self::assertFalse(TotpKeyRing::fromJson('{}', '')->configured());
        self::assertSame($key, TotpKeyRing::fromJson('{"1":"' . base64_encode($key) . '"}', '1')->key('1'));

        foreach ([
            ['[]', ''], ['null', ''], ['{', ''], ['{}', 'new'], [$json, 'absent'], [$json, ''],
            ['{"new":123}', 'new'], ['{"new":"' . base64_encode(random_bytes(31)) . '"}', 'new'],
            ['{"new":"' . base64_encode($key) . '\n"}', 'new'],
            [json_encode(['db:abc' => base64_encode($key)], JSON_THROW_ON_ERROR), 'db:abc'],
            [json_encode([str_repeat('a', 33) => base64_encode($key)], JSON_THROW_ON_ERROR), str_repeat('a', 33)],
            [json_encode(array_fill_keys(range('a', 'i'), base64_encode($key)), JSON_THROW_ON_ERROR), 'a'],
            [str_repeat(' ', 8193), ''],
        ] as [$invalid, $active]) {
            try {
                TotpKeyRing::fromJson($invalid, $active);
                self::fail('Invalid key configuration was accepted.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Invalid TOTP key configuration.', $error->getMessage());
            }
        }
    }
}