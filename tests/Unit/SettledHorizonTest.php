<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Realtime\SettledHorizon;
use PHPUnit\Framework\TestCase;

/** Which sample is settled, given when the oldest open writer started (#286). */
final class SettledHorizonTest extends TestCase
{
    private const SECOND = 1_000_000;

    private const SAMPLES = [
        ['at' => 10 * self::SECOND, 'value' => 10],
        ['at' => 20 * self::SECOND, 'value' => 20],
        ['at' => 30 * self::SECOND, 'value' => 30],
    ];

    public function testWithNoOpenWriterTheNewestSampleIsSettled(): void
    {
        self::assertSame(30, SettledHorizon::choose(self::SAMPLES, null));
    }

    public function testOnlySamplesTakenAtLeastASecondBeforeTheOldestWriterStartedCount(): void
    {
        self::assertSame(20, SettledHorizon::choose(self::SAMPLES, 25 * self::SECOND));
        self::assertSame(20, SettledHorizon::choose(self::SAMPLES, 21 * self::SECOND + 1));
        self::assertSame(10, SettledHorizon::choose(self::SAMPLES, 21 * self::SECOND), 'Exactly the margin is not enough.');
        self::assertSame(10, SettledHorizon::choose(self::SAMPLES, 20 * self::SECOND + 500_000), 'Within the clock margin.');
        self::assertNull(SettledHorizon::choose(self::SAMPLES, 11 * self::SECOND));
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
