<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Realtime\RealtimeEvent;
use ChitChat\Realtime\SseEncoder;
use PHPUnit\Framework\TestCase;

final class SseEncoderTest extends TestCase
{
    public function testPersistentEventContainsCursorTypeAndJsonData(): void
    {
        $event = new RealtimeEvent(
            id: 42,
            type: 'ping',
            roomId: 7,
            targetUserId: 9,
            actorUserId: 3,
            payload: ['message' => 'hello'],
            createdAt: '2026-07-16 21:00:00+00',
        );

        $encoded = SseEncoder::event($event);

        self::assertStringContainsString("id: 42\n", $encoded);
        self::assertStringContainsString("event: ping\n", $encoded);
        self::assertStringContainsString('"message":"hello"', $encoded);
        self::assertStringEndsWith("\n\n", $encoded);
    }

    public function testHeartbeatIsAnSseComment(): void
    {
        self::assertStringStartsWith(': heartbeat ', SseEncoder::heartbeat());
    }
}
