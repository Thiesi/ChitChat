<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\RoomService;

final class EventRepositoryTest extends DatabaseTestCase
{
    public function testEventsAreOrderedAndFilteredByScope(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $events = new EventRepository($this->pdo);

        $roomEvent = $events->publish(
            'room_broadcast',
            ['message' => 'Room notice'],
            roomId: $room->id,
            actorUserId: $admin->id,
        );
        $globalEvent = $events->publish(
            'global_broadcast',
            ['message' => 'Global notice'],
            actorUserId: $admin->id,
        );
        $targetEvent = $events->publish(
            'ping',
            ['message' => 'Hello'],
            roomId: $room->id,
            targetUserId: $member->id,
            actorUserId: $admin->id,
        );

        self::assertSame(
            [$roomEvent->id, $globalEvent->id, $targetEvent->id],
            array_map(static fn ($event): int => $event->id, $events->visibleAfter($member, 0)),
        );
        self::assertSame(
            [$globalEvent->id],
            array_map(static fn ($event): int => $event->id, $events->visibleAfter($outsider, 0)),
        );
        self::assertSame(
            [$roomEvent->id, $globalEvent->id],
            array_map(static fn ($event): int => $event->id, $events->visibleAfter($admin, 0)),
        );
        self::assertSame(
            [$targetEvent->id],
            array_map(
                static fn ($event): int => $event->id,
                $events->visibleAfter($member, $globalEvent->id),
            ),
        );
    }
}
