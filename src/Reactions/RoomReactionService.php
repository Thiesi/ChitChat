<?php

declare(strict_types=1);

namespace ChitChat\Reactions;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use PDO;
use RuntimeException;

final class RoomReactionService
{
    private readonly RoomRepository $rooms;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->events = new EventRepository($pdo);
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    public function add(AuthenticatedUser $actor, int $messageId, string $emoji): array
    {
        $emoji = ReactionVocabulary::require($emoji);
        [$roomId, $deletedAt] = $this->requireMessage($actor, $messageId);
        if ($deletedAt !== null) {
            throw new ApiException(409, 'message_already_deleted', 'Message is already deleted.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_message_reactions (message_id, user_id, emoji)
VALUES (:message_id, :user_id, :emoji)
ON CONFLICT (message_id, user_id, emoji) DO NOTHING
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message reaction add.');
        }
        $statement->execute(['message_id' => $messageId, 'user_id' => $actor->id, 'emoji' => $emoji]);

        return $this->publishAndReturn($messageId, $roomId, $actor->id);
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    public function remove(AuthenticatedUser $actor, int $messageId, string $emoji): array
    {
        $emoji = ReactionVocabulary::require($emoji);
        [$roomId] = $this->requireMessage($actor, $messageId);

        $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM room_message_reactions
WHERE message_id = :message_id AND user_id = :user_id AND emoji = :emoji
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message reaction removal.');
        }
        $statement->execute(['message_id' => $messageId, 'user_id' => $actor->id, 'emoji' => $emoji]);

        return $this->publishAndReturn($messageId, $roomId, $actor->id);
    }

    /** @return array{0:int, 1:?string} room_id and deleted_at */
    private function requireMessage(AuthenticatedUser $actor, int $messageId): array
    {
        if ($messageId < 1) {
            throw new ApiException(400, 'validation_error', 'message_id must be positive.');
        }

        $statement = $this->pdo->prepare('SELECT room_id, deleted_at FROM room_messages WHERE id = :id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message reaction lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }

        $roomId = (int) $row['room_id'];
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        RoomAuthorization::requireHistory($actor, $room);
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);

        return [$roomId, $row['deleted_at'] === null ? null : (string) $row['deleted_at']];
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    private function publishAndReturn(int $messageId, int $roomId, int $actorId): array
    {
        $reactions = $this->reactionsFor($messageId, $actorId);
        $this->events->publish(
            type: 'message_reaction_changed',
            payload: [
                'message_kind' => 'room',
                'message_id' => $messageId,
                'room_id' => $roomId,
                'reactions' => $reactions,
            ],
            roomId: $roomId,
            actorUserId: $actorId,
        );

        return $reactions;
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    private function reactionsFor(int $messageId, int $viewerUserId): array
    {
        $subquery = ReactionHydrator::correlatedSubquery(
            'room_message_reactions',
            ':message_id',
            ':viewer_user_id',
        );
        $statement = $this->pdo->prepare("SELECT {$subquery} AS reactions_json");
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message reaction summary.');
        }
        $statement->execute(['message_id' => $messageId, 'viewer_user_id' => $viewerUserId]);
        $row = $statement->fetch();

        return ReactionHydrator::hydrateJson(is_array($row) ? ($row['reactions_json'] ?? null) : null);
    }
}
