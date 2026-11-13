<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;

final class RoomAuthorization
{
    public static function canCreate(AuthenticatedUser $actor): bool
    {
        return self::hasAnyRole($actor, ['super_admin', 'admin', 'chat_admin']);
    }

    public static function canView(AuthenticatedUser $actor, Room $room): bool
    {
        if ($actor->guest) {
            return $room->guestAccess !== 'none';
        }
        return self::canModerateAnyRoom($actor)
            || $room->visibility !== 'private'
            || $room->isMember()
            || $room->invited;
    }

    public static function canReadHistory(AuthenticatedUser $actor, Room $room): bool
    {
        if ($actor->guest) {
            return $room->guestAccess !== 'none';
        }
        return self::canModerateAnyRoom($actor)
            || $room->visibility !== 'private'
            || $room->isMember();
    }

    /** Members post in rooms they joined; a guest only where the room lets guests write. */
    public static function canPost(AuthenticatedUser $actor, Room $room): bool
    {
        return $room->isMember() && (!$actor->guest || $room->guestsMayPost());
    }

    public static function canManage(AuthenticatedUser $actor, Room $room): bool
    {
        return self::canManageAnyRoom($actor) || $room->memberRole === 'owner';
    }

    /** The global roles that manage every room (rename, delete, set roles, transfer ownership). */
    public static function canManageAnyRoom(AuthenticatedUser $actor): bool
    {
        return self::hasAnyRole($actor, ['super_admin', 'admin', 'chat_admin']);
    }

    public static function canModerate(AuthenticatedUser $actor, Room $room): bool
    {
        return self::canModerateAnyRoom($actor)
            || in_array($room->memberRole, ['owner', 'moderator'], true);
    }

    public static function canModerateAnyRoom(AuthenticatedUser $actor): bool
    {
        return self::hasAnyRole($actor, ['super_admin', 'admin', 'chat_admin', 'global_moderator']);
    }

    public static function requireCreate(AuthenticatedUser $actor): void
    {
        if (!self::canCreate($actor)) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to create rooms.');
        }
    }

    /**
     * A room the caller can't see answers exactly like one that doesn't exist (#326): same
     * status, code and message, so no route confirms that a private room exists. Routes
     * addressed by a message or attachment ID pass their own not-found instead. Runs before
     * every other check that reveals state; the other require* helpers call it first.
     */
    public static function requireVisible(
        AuthenticatedUser $actor,
        Room $room,
        string $code = 'room_not_found',
        string $message = 'Room not found.',
    ): void {
        if (!self::canView($actor, $room)) {
            throw new ApiException(404, $code, $message);
        }
    }

    /** For routes addressed by a message ID: an invisible room answers like a missing message. */
    public static function requireMessageVisible(AuthenticatedUser $actor, Room $room): void
    {
        self::requireVisible($actor, $room, 'message_not_found', 'Message not found.');
    }

    public static function requireView(AuthenticatedUser $actor, Room $room): void
    {
        self::requireVisible($actor, $room);
    }

    public static function requireHistory(AuthenticatedUser $actor, Room $room): void
    {
        self::requireVisible($actor, $room);
        if (!self::canReadHistory($actor, $room)) {
            throw new ApiException(403, 'room_forbidden', 'Join the private room before reading its history.');
        }
    }

    public static function requirePost(AuthenticatedUser $actor, Room $room): void
    {
        self::requireVisible($actor, $room);
        if (!$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before sending messages.');
        }
        if (!self::canPost($actor, $room)) {
            throw new ApiException(403, 'guest_read_only', 'Guests can read this room. Create an account to join the conversation.');
        }
    }

    public static function requireManage(AuthenticatedUser $actor, Room $room): void
    {
        self::requireVisible($actor, $room);
        if (!self::canManage($actor, $room)) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to manage this room.');
        }
    }

    public static function requireModerate(AuthenticatedUser $actor, Room $room): void
    {
        self::requireVisible($actor, $room);
        if (!self::canModerate($actor, $room)) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to moderate this room.');
        }
    }

    /** @param list<string> $roles */
    private static function hasAnyRole(AuthenticatedUser $actor, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
