<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;

/**
 * Tells clients that their room list may have changed, so they re-fetch it.
 * The rooms_changed event carries no room data: the authorized room-list
 * endpoint decides what each viewer sees. The audience follows the existing
 * event visibility rules, so a private room is never announced to people who
 * cannot see it.
 */
final class RoomListSignal
{
    public function __construct(private readonly EventRepository $events)
    {
    }

    /**
     * A room was created, changed or deleted. Public rooms (including a room
     * that was or became public) signal everyone; unlisted and private rooms
     * signal their members and the moderators who can see every room.
     */
    public function room(int $roomId, bool $visibleToEveryone, int $actorUserId): void
    {
        $this->events->publish(
            type: 'rooms_changed',
            payload: [],
            roomId: $visibleToEveryone ? null : $roomId,
            actorUserId: $actorUserId,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );
    }

    /** One user's own view of a room changed: membership, role or invitation. */
    public function user(int $userId, int $actorUserId): void
    {
        $this->events->publish(
            type: 'rooms_changed',
            payload: [],
            targetUserId: $userId,
            actorUserId: $actorUserId,
            expiresAt: new DateTimeImmutable('+1 hour'),
        );
    }
}
