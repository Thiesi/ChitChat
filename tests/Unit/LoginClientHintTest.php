<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\LoginSessionRepository;
use PHPUnit\Framework\TestCase;

final class LoginClientHintTest extends TestCase
{
    public function testHintsClassifyOnlyBoundedClientAndPlatformNames(): void
    {
        foreach ([
            [null, 'Unknown client'],
            ['private-user-data', 'Unknown client'],
            ['Mozilla/5.0 (Windows NT 10.0) Chrome/140 Safari/537 Edg/140', 'Edge on Windows'],
            ['Mozilla/5.0 (Android) Chrome/140 Safari/537', 'Chrome on Android'],
            ['Mozilla/5.0 (iPhone) FxiOS/140 Safari/605', 'Firefox on iOS'],
            ['Mozilla/5.0 (Macintosh) Version/18 Safari/605', 'Safari on macOS'],
            ['ChitChat-Mobile/1.0 (Android 15)', 'ChitChat app on Android'],
            [str_repeat('x', 513) . ' Firefox/140 Windows', 'Unknown client'],
        ] as [$agent, $expected]) {
            self::assertSame($expected, LoginSessionRepository::clientHint($agent));
        }
    }
}
