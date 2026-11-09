<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\NativeAuth\NativeAuthBootstrap;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class NativeAuthBootstrapTest extends TestCase
{
    public function testBootstrapSecretAndCsrfAreBoundToTransactionAndKindWithoutAPhpSession(): void
    {
        $_SESSION = [];
        $id = NativeAuthConfiguration::token();
        $entry = NativeAuthBootstrap::create($id, 'entry');
        $parsed = NativeAuthBootstrap::verify($entry['cookie'], $entry['csrf_token'], 'entry');
        self::assertSame($id, $parsed['id']);
        self::assertSame($entry['hash'], $parsed['hash']);
        self::assertNotSame($entry['hash'], explode('.', $entry['cookie'])[2]);
        self::assertSame([], $_SESSION);
        $other = NativeAuthBootstrap::create(NativeAuthConfiguration::token(), 'entry');
        foreach ([
            [$entry['cookie'], '', 'entry'],
            [$entry['cookie'], $other['csrf_token'], 'entry'],
            [$entry['cookie'], $entry['csrf_token'], 'callback'],
            [$other['cookie'], $entry['csrf_token'], 'entry'],
        ] as [$cookie, $csrf, $kind]) {
            try {
                NativeAuthBootstrap::verify($cookie, $csrf, $kind);
                self::fail('Cross-context bootstrap was accepted.');
            } catch (ApiException $exception) {
                self::assertSame(403, $exception->status);
            }
        }
        self::assertNull(NativeAuthBootstrap::parse(str_repeat('x', 101)));
        self::assertNull(NativeAuthBootstrap::parse($id . '.unknown.' . NativeAuthConfiguration::token()));
    }
}
