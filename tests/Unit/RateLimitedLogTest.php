<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Observability\RateLimitedLog;
use ChitChat\Tests\Support\TestScratch;
use PHPUnit\Framework\TestCase;

/** Warnings from many workers, once per interval, kind and instance; never blocked by a broken /tmp (#286). */
final class RateLimitedLogTest extends TestCase
{
    public function testOnceAPerIntervalPerKindAndPerInstance(): void
    {
        $directory = TestScratch::directory('rate-limited-log');
        try {
            $lines = [];
            $sink = static function (string $line) use (&$lines): void {
                $lines[] = $line;
            };
            $first = new RateLimitedLog('instance-a', 60, $directory, $sink);
            // Another worker of the same instance shares the marker.
            $sameInstance = new RateLimitedLog('instance-a', 60, $directory, $sink);
            $otherInstance = new RateLimitedLog('instance-b', 60, $directory, $sink);

            self::assertTrue($first->warn('stuck', 'one'));
            self::assertFalse($sameInstance->warn('stuck', 'two'), 'Within the interval.');
            self::assertTrue($sameInstance->warn('unknown', 'three'), 'Another kind.');
            self::assertTrue($otherInstance->warn('stuck', 'four'), 'Another instance on the same host is not silenced.');
            self::assertSame(['one', 'three', 'four'], $lines);
            self::assertSame([], glob($directory . '/*.warn.*') ?: [], 'No temporary files left behind.');
        } finally {
            TestScratch::remove($directory);
        }
    }

    public function testAMissingDirectoryStillLogsEveryTime(): void
    {
        $lines = [];
        $log = new RateLimitedLog('instance-a', 60, '/nonexistent/chitchat-' . bin2hex(random_bytes(4)), static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        self::assertTrue($log->warn('stuck', 'one'));
        self::assertTrue($log->warn('stuck', 'two'));
        self::assertSame(['one', 'two'], $lines);
    }

    public function testTheInstanceKeyDependsOnTheDatabase(): void
    {
        $key = RateLimitedLog::instanceKey(...);
        self::assertSame($key('db', 5432, 'chat_a'), $key('db', 5432, 'chat_a'));
        self::assertNotSame($key('db', 5432, 'chat_a'), $key('db', 5432, 'chat_b'));
        self::assertNotSame($key('db', 5432, 'chat_a'), $key('db', 5433, 'chat_a'));
    }
}
