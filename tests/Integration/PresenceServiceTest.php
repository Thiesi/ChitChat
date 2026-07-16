<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Presence\PresenceService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\RoomService;

final class PresenceServiceTest extends DatabaseTestCase
{
    private const FIRST_CONNECTION = '11111111-1111-4111-8111-111111111111';
    private const SECOND_CONNECTION = '22222222-2222-4222-8222-222222222222';

    public function testRoomMemberHeartbeatAppearsAndPublishesOneChange(): void
    {
        [$admin, $member, $outsider, $room] = $this->createRoomWithMember(600);
        $presence = new PresenceService($this->pdo, $this->config);

        $status = $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, true);
        self::assertSame($room->id, $status['room_id']);
        self::assertFalse($status['expired']);
        self::assertSame(0, $status['idle_seconds']);
        self::assertSame([
            [
                'id' => $member->id,
                'username' => 'Member',
                'idle_seconds' => 0,
                'connections' => 1,
            ],
        ], $presence->list($member, $room->id));

        $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, false);
        $events = (new EventRepository($this->pdo))->visibleAfter($admin, 0);
        self::assertSame(['presence_changed'], array_map(
            static fn ($event): string => $event->type,
            $events,
        ));

        try {
            $presence->list($outsider, $room->id);
            self::fail('Expected presence membership requirement.');
        } catch (ApiException $exception) {
            self::assertSame('presence_membership_required', $exception->errorCode);
        }
    }

    public function testMultipleConnectionsAggregateAndExpireIndependently(): void
    {
        [, $member, , $room] = $this->createRoomWithMember(0);
        $presence = new PresenceService($this->pdo, $this->config);
        $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, true);
        $presence->heartbeat($member, self::SECOND_CONNECTION, $room->id, true);

        $users = $presence->list($member, $room->id);
        self::assertCount(1, $users);
        self::assertSame(2, $users[0]['connections']);

        $this->pdo->exec(
            "UPDATE room_presence SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE connection_id = '"
            . self::FIRST_CONNECTION
            . "'",
        );
        $presence->expireStale();
        self::assertSame(1, $presence->list($member, $room->id)[0]['connections']);

        $this->pdo->exec(
            "UPDATE room_presence SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE connection_id = '"
            . self::SECOND_CONNECTION
            . "'",
        );
        $presence->expireStale();
        self::assertSame([], $presence->list($member, $room->id));
    }

    public function testInactivityExpiresPresenceWithoutRemovingMembership(): void
    {
        [, $member, , $room] = $this->createRoomWithMember(120);
        $presence = new PresenceService($this->pdo, $this->config);
        $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, true);

        $this->pdo->exec(
            "UPDATE room_presence SET last_interaction_at = NOW() - INTERVAL '121 seconds'",
        );
        $status = $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, false);

        self::assertTrue($status['expired']);
        self::assertNull($status['room_id']);
        self::assertSame([], $presence->list($member, $room->id));
        self::assertSame('member', (new RoomService($this->pdo))->get($member, $room->id)->memberRole);

        $reactivated = $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, true);
        self::assertFalse($reactivated['expired']);
        self::assertSame($room->id, $reactivated['room_id']);
    }

    public function testHeartbeatWarnsBeforeConfiguredInactivityExpiry(): void
    {
        [, $member, , $room] = $this->createRoomWithMember(120);
        $presence = new PresenceService($this->pdo, $this->config);
        $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, true);
        $this->pdo->exec(
            "UPDATE room_presence SET last_interaction_at = NOW() - INTERVAL '70 seconds'",
        );

        $status = $presence->heartbeat($member, self::FIRST_CONNECTION, $room->id, false);

        self::assertNotNull($status['warning_seconds']);
        self::assertGreaterThan(0, $status['warning_seconds']);
        self::assertLessThanOrEqual($this->config->inactivityWarningSeconds, $status['warning_seconds']);
    }

    /** @return array{\ChitChat\Auth\AuthenticatedUser, \ChitChat\Auth\AuthenticatedUser, \ChitChat\Auth\AuthenticatedUser, \ChitChat\Room\Room} */
    private function createRoomWithMember(int $inactivityTimeout): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create(
            $admin,
            'general',
            'General',
            '',
            'public',
            0,
            $inactivityTimeout,
            '127.0.0.1',
        );
        $rooms->join($member, $room->id, '127.0.0.2');

        return [$admin, $member, $outsider, $room];
    }
}
