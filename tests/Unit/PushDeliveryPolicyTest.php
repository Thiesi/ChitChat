<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\WebPush\PushDeliveryPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PushDeliveryPolicyTest extends TestCase
{
    public function testLifetimesAndUrgencyAreExplicitForSocialAndOtherKinds(): void
    {
        foreach (['mentioned', 'pinged', 'direct_message_received'] as $kind) {
            self::assertSame(1800, PushDeliveryPolicy::lifetime($kind));
            self::assertSame('high', PushDeliveryPolicy::urgency($kind));
        }
        foreach (['admin_password_reset', 'muted', 'revision_review', 'future_kind'] as $kind) {
            self::assertSame(21600, PushDeliveryPolicy::lifetime($kind));
            self::assertSame('normal', PushDeliveryPolicy::urgency($kind));
        }
        self::assertSame(0, PushDeliveryPolicy::remainingSeconds(new DateTimeImmutable('2026-11-09T00:00:01.100Z'), new DateTimeImmutable('2026-11-09T00:00:00.900Z')));
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $delay = PushDeliveryPolicy::retryDelay($attempt);
            $base = min(120, 15 * 2 ** ($attempt - 1));
            self::assertGreaterThanOrEqual($base, $delay);
            self::assertLessThanOrEqual(min(120, $base + (int) ($base / 5)), $delay);
        }
    }

    public function testProviderRetryAfterIsBoundedAndAcceptsSecondsAndHttpDatesOnly(): void
    {
        $now = new DateTimeImmutable('2026-11-09T00:00:00Z');
        self::assertSame(120, PushDeliveryPolicy::retryAfter('120', $now));
        self::assertSame(60, PushDeliveryPolicy::retryAfter('Mon, 09 Nov 2026 00:01:00 GMT', $now));
        self::assertSame(0, PushDeliveryPolicy::retryAfter('Mon, 09 Nov 2026 00:00:00 GMT', $now));
        self::assertSame(PHP_INT_MAX, PushDeliveryPolicy::retryAfter(str_repeat('9', 100), $now));
        foreach (['', '-1', 'tomorrow', '1.5', "bad\0date", str_repeat('x', 129)] as $value) {
            self::assertNull(PushDeliveryPolicy::retryAfter($value, $now));
        }
    }
}
