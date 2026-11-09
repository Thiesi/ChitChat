<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\WebPush\PushNotification;
use ChitChat\WebPush\PushPayload;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PushPayloadTest extends TestCase
{
    public function testPayloadKeepsTimelineIdentityAndBindingWithinPlaintextBudget(): void
    {
        $notification = new PushNotification(123456789, 4, 'mentioned', [], new DateTimeImmutable(), false);
        $rendered = ['title' => str_repeat('👩🏽‍💻', 64), 'message' => str_repeat('名\"\\', 128),
            'link' => '/chat.php?room_id=123456789&message_id=123456789', 'details' => []];
        $json = PushPayload::build($notification, $rendered, str_repeat('🏘', 100), str_repeat('E', 64), str_repeat('B', 64));
        self::assertLessThan(3000, strlen($json));
        $payload = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('mentioned', $payload['kind']);
        self::assertSame(123456789, $payload['notification_id']);
        self::assertSame(str_repeat('E', 64), $payload['event_id']);
        self::assertSame(str_repeat('B', 64), $payload['binding']);
        self::assertSame($rendered['link'], $payload['link']);
        self::assertSame(1, mb_check_encoding($json, 'UTF-8') ? 1 : 0);
        $small = json_decode(PushPayload::build($notification,
            ['title' => 'New mention', 'message' => 'A mentioned you.', 'link' => '/chat.php?room_id=1', 'details' => []],
            'ChitChat', 'event-1', null), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame('A mentioned you.', $small['body']);
        self::assertSame('New mention', $small['title']);
        self::assertSame('ChitChat', $small['app_name']);
        self::assertArrayNotHasKey('binding', $small);
    }

    public function testMaximumRealRendererFieldsFitWithoutChangingTheirMeaning(): void
    {
        $renderer = new \ChitChat\Account\PrivacyNotificationService($this->createStub(\PDO::class));
        $context = ['sender_username' => str_repeat('a', 32), 'room_name' => str_repeat('🏘', 120),
            'room_id' => PHP_INT_MAX, 'message_id' => PHP_INT_MAX, 'ping_id' => PHP_INT_MAX,
            'message_kind' => 'room', 'broadcast' => true, 'expires_at' => '9999-12-31T23:59:59+00:00'];
        foreach (['mentioned', 'pinged', 'muted', 'room_restored'] as $kind) {
            $rendered = $renderer->renderText($kind, $context);
            $json = PushPayload::build(new PushNotification(PHP_INT_MAX, 4, $kind, $context, new DateTimeImmutable(), false),
                $rendered, str_repeat('🏘', 64), str_repeat('E', 64), str_repeat('B', 64));
            self::assertLessThan(3000, strlen($json));
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            self::assertSame($rendered['title'], $decoded['title']);
            self::assertSame($rendered['message'], $decoded['body']);
            self::assertSame($rendered['link'], $decoded['link']);
            self::assertSame(str_repeat('🏘', 64), $decoded['app_name']);
        }
    }
}
