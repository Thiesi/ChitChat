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
        return self::hasAnyRole($actor, ['super_admin', 'admin', 'chat_admin'])
            || $room->memberRole === 'owner';
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

    public static function requireView(AuthenticatedUser $actor, Room $room): void
    {
        if (!self::canView($actor, $room)) {
            throw new ApiException(403, 'room_forbidden', 'You are not allowed to view this room.');
        }
    }

    public static function requireHistory(AuthenticatedUser $actor, Room $room): void
    {
        if (!self::canReadHistory($actor, $room)) {
            throw new ApiException(403, 'room_forbidden', 'Join the private room before reading its history.');
        }
    }

    public static function requirePost(AuthenticatedUser $actor, Room $room): void
    {
        if (!$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before sending messages.');
        }
        if (!self::canPost($actor, $room)) {
            throw new ApiException(403, 'guest_read_only', 'Guests can read this room. Create an account to join the conversation.');
        }
    }

    public static function requireManage(AuthenticatedUser $actor, Room $room): void
    {
        if (!self::canManage($actor, $room)) {
            throw new ApiException(403, 'forbidden', 'You are not allowed to manage this room.');
        }
    }

    public static function requireModerate(AuthenticatedUser $actor, Room $room): void
    {
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
