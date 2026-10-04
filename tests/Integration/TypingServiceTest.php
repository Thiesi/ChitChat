<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\TypingService;
use ChitChat\Room\RoomService;

final class TypingServiceTest extends DatabaseTestCase
{
    public function testRoomTypingReachesMembersOnlyAndIsThrottled(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'one more secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'quiet', 'Quiet', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $typing = new TypingService($this->pdo);

        self::assertTrue($typing->inRoom($owner, $room->id));
        self::assertFalse($typing->inRoom($owner, $room->id), 'A second signal within seconds is dropped.');

        $seen = $this->typingEvents($member);
        self::assertCount(1, $seen);
        // JSONB reorders keys, so compare the fields rather than the arrays.
        self::assertSame($room->id, $seen[0]->payload['room_id'] ?? null);
        self::assertSame(['id' => $owner->id, 'username' => 'Owner'], $seen[0]->payload['user'] ?? null);
        self::assertCount(2, $seen[0]->payload, 'Nothing but the room and who is typing.');
        self::assertSame([], $this->typingEvents($outsider), 'Only members see typing in a room.');

        // Expired signals are never delivered.
        $this->pdo->exec("UPDATE realtime_events SET expires_at = NOW() - INTERVAL '1 second' WHERE event_type = 'typing'");
        self::assertSame([], $this->typingEvents($member));

        $this->expectExceptionObject(new ApiException(403, 'membership_required', 'Join the room before typing in it.'));
        $typing->inRoom($outsider, $room->id);
    }

    public function testDirectTypingReachesTheOtherPersonUnlessBlocked(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alex = $auth->register('Alex', 'a very secure password', '127.0.0.1');
        $sam = $auth->register('Sam', 'another secure password', '127.0.0.2');
        $third = $auth->register('Third', 'one more secure password', '127.0.0.3');
        $typing = new TypingService($this->pdo);

        self::assertTrue($typing->inConversation($alex, $sam->id));
        self::assertCount(1, $this->typingEvents($sam));
        self::assertSame([], $this->typingEvents($third));
        self::assertSame([], $this->typingEvents($alex), 'The typist is not told about themselves.');

        (new DirectMessageBlockService($this->pdo))->block($sam, $alex->id);
        $this->expectExceptionObject(new ApiException(403, 'direct_message_unavailable', 'Direct messaging is unavailable for this user.'));
        $typing->inConversation($alex, $sam->id);
    }

    /** @return list<\ChitChat\Realtime\RealtimeEvent> */
    private function typingEvents(AuthenticatedUser $viewer): array
    {
        return array_values(array_filter(
            (new EventRepository($this->pdo))->visibleAfter($viewer, 0),
            static fn ($event): bool => $event->type === 'typing',
        ));
    }
}
