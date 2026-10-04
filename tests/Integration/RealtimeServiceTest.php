<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\BroadcastService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;

final class RealtimeServiceTest extends DatabaseTestCase
{
    public function testPingTargetsOnlyAnotherRoomMember(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        $pings = new PingService($this->pdo);
        $ping = $pings->send($admin, $room->id, 'member', 'Please look');
        $events = new EventRepository($this->pdo);

        self::assertSame(['id' => $admin->id, 'username' => 'Admin'], $ping['sender']);
        self::assertSame(['id' => $member->id, 'username' => 'Member'], $ping['target']);
        self::assertSame('Please look', $ping['message']);

        // Sender and target each get their own event; nobody else does.
        foreach ([$admin, $member] as $participant) {
            $received = self::withoutRoomListSignals($events->visibleAfter($participant, 0));
            self::assertSame(['ping'], array_column($this->eventArrays($received), 'type'));
            self::assertSame($ping['id'], $received[0]->payload['ping']['id']);
        }
        self::assertSame([], self::withoutRoomListSignals($events->visibleAfter($outsider, 0)));

        // The ping is durable and private to its two participants.
        self::assertSame([$ping['id']], array_column($pings->history($admin, $room->id), 'id'));
        self::assertSame([$ping['id']], array_column($pings->history($member, $room->id), 'id'));
        self::assertSame([], $pings->history($outsider, $room->id));

        // It reaches the target through the notification list, without copying its text.
        $notification = $this->pdo->query(
            "SELECT user_id, context_json::text AS context FROM account_notifications WHERE kind = 'pinged'",
        )?->fetchAll();
        self::assertCount(1, $notification);
        self::assertSame($member->id, (int) $notification[0]['user_id']);
        self::assertStringNotContainsString('Please look', (string) $notification[0]['context']);

        try {
            (new PingService($this->pdo))->send($admin, $room->id, 'outsider', 'Nope');
            self::fail('Expected non-member ping target rejection.');
        } catch (ApiException $exception) {
            self::assertSame('ping_target_not_found', $exception->errorCode);
        }
    }

    public function testBroadcastPermissionsScopesAndAuditing(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $broadcasts = new BroadcastService($this->pdo);

        $roomEvent = $broadcasts->room($admin, $room->id, 'Room notice', '127.0.0.1');
        $globalEvent = $broadcasts->global($admin, 'Global notice', '127.0.0.1');
        $events = new EventRepository($this->pdo);

        self::assertSame(
            [$roomEvent->id, $globalEvent->id],
            array_column($this->eventArrays(self::withoutRoomListSignals($events->visibleAfter($member, 0))), 'id'),
        );
        self::assertSame(
            [$globalEvent->id],
            array_column($this->eventArrays(self::withoutRoomListSignals($events->visibleAfter($outsider, 0))), 'id'),
        );
        self::assertSame(
            2,
            (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'realtime.%broadcast'")?->fetchColumn(),
        );

        try {
            $broadcasts->room($member, $room->id, 'Unauthorized', '127.0.0.2');
            self::fail('Expected room broadcast permission rejection.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
    }

    public function testMessageWritesPublishRealtimeEvents(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $messages = new MessageService($this->pdo);

        $message = $messages->send($member, $room->id, 'Hello');
        $messages->delete($admin, $message['id'], '127.0.0.1');
        $events = self::withoutRoomListSignals((new EventRepository($this->pdo))->visibleAfter($member, 0));

        self::assertSame(['room_message', 'message_deleted'], array_map(
            static fn ($event): string => $event->type,
            $events,
        ));
    }

    /**
     * @param list<\ChitChat\Realtime\RealtimeEvent> $events
     * @return list<array{id:int, type:string, room_id:?int, actor_user_id:?int, payload:array<string, mixed>, created_at:string}>
     */
    private function eventArrays(array $events): array
    {
        return array_map(static fn ($event): array => $event->toArray(), $events);
    }
}
