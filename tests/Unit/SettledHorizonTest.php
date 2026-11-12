<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Realtime\SettledHorizon;
use PHPUnit\Framework\TestCase;

/** Which sample is settled, given when the oldest open writer started (#286). */
final class SettledHorizonTest extends TestCase
{
    private const SAMPLES = [
        ['at' => 1_000, 'value' => 10],
        ['at' => 2_000, 'value' => 20],
        ['at' => 3_000, 'value' => 30],
    ];

    public function testWithNoOpenWriterTheNewestSampleIsSettled(): void
    {
        self::assertSame(30, SettledHorizon::choose(self::SAMPLES, null));
    }

    public function testOnlySamplesTakenBeforeTheOldestWriterStartedCount(): void
    {
        self::assertSame(20, SettledHorizon::choose(self::SAMPLES, 2_500));
        self::assertSame(10, SettledHorizon::choose(self::SAMPLES, 2_000), 'A sample at the same microsecond is not before it.');
        self::assertNull(SettledHorizon::choose(self::SAMPLES, 1_000));
    }

    public function testAWriterWhoseStartIsUnknownBlocksEverySample(): void
    {
        self::assertNull(SettledHorizon::choose(self::SAMPLES, PHP_INT_MIN));
    }

    public function testNoSamplesNoHorizon(): void
    {
        self::assertNull(SettledHorizon::choose([], null));
    }
}
