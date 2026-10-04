<?php

declare(strict_types=1);

namespace ChitChat\Moderation;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Room\ModerationRank;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;
use PDO;

/**
 * What a viewer may do to a person from the profile card: in the room they
 * are looking at, and everywhere. Only what would succeed is offered, with
 * the person's current ban and mutes; null when there is nothing to offer.
 */
final class ModerationOptions
{
    /** Signing out and banning stay with Administrators and up. */
    private const USER_MANAGEMENT_RANK = 4;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return ?array{room: ?array<string, mixed>, everywhere: ?array<string, mixed>} */
    public function for(AuthenticatedUser $viewer, int $targetId, ?int $roomId): ?array
    {
        if ($viewer->id === $targetId) {
            return null;
        }
        $ranks = new ModerationRank($this->pdo);
        $mutes = new MuteService($this->pdo);
        $rooms = new RoomRepository($this->pdo);

        $room = null;
        $roomRecord = $roomId === null ? null : $rooms->findForUser($roomId, $viewer->id);
        if ($roomRecord !== null && RoomAuthorization::canModerate($viewer, $roomRecord)) {
            $outranks = $ranks->inRoom($viewer, $roomRecord, $targetId);
            $role = $rooms->membershipRole($roomRecord->id, $targetId);
            $manage = RoomAuthorization::canManage($viewer, $roomRecord) && $outranks && $role !== null && $role !== 'owner';
            $room = [
                'id' => $roomRecord->id,
                'name' => $roomRecord->name,
                'member_role' => $role,
                'can_set_moderator' => $manage,
                'can_remove' => $manage,
                'can_mute' => $outranks,
                'mute' => $mutes->current($targetId, $roomRecord->id),
            ];
            if (!$room['can_set_moderator'] && !$room['can_mute']) {
                $room = null;
            }
        }

        $manageUsers = ModerationRank::globalRankOf($viewer) >= self::USER_MANAGEMENT_RANK && $ranks->globally($viewer, $targetId);
        $muteEverywhere = $mutes->canMuteEverywhere($viewer, $targetId);
        $everywhere = null;
        if ($manageUsers || $muteEverywhere) {
            $ban = $manageUsers ? (new UserRepository($this->pdo))->activeBan($targetId) : null;
            $everywhere = [
                'can_kick' => $manageUsers,
                'can_ban' => $manageUsers,
                'can_mute' => $muteEverywhere,
                'can_open_administration' => $viewer->canManageUsers(),
                'ban' => $ban,
                'mute' => $mutes->current($targetId, null),
            ];
        }

        return $room === null && $everywhere === null ? null : ['room' => $room, 'everywhere' => $everywhere];
    }
}
