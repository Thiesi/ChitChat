<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Reactions\ReactionHydrator;
use ChitChat\Realtime\EventRepository;
use PDO;
use RuntimeException;
use Throwable;

final class RoomMessageMutationService
{
    private readonly RoomRepository $rooms;
    private readonly AuditLogger $audit;
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->events = new EventRepository($pdo);
    }

    /**
     * @param list<int> $messageIds
     * @return list<array{
     *   id:int,
     *   body:?string,
     *   type:string,
     *   edited_at:?string,
     *   deleted:bool,
     *   deletion_kind:?string,
     *   can_edit:bool,
     *   can_delete:bool,
     *   mentions:list<array{user_id:int, username:string, broadcast:bool}>,
     *   reactions:list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}>
     * }>
     */
    public function metadata(AuthenticatedUser $actor, int $roomId, array $messageIds): array
    {
        $room = $this->requireRoom($actor, $roomId);
        RoomAuthorization::requireHistory($actor, $room);
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);
        if ($messageIds === []) {
            return [];
        }

        [$placeholders, $parameters] = $this->idParameters($messageIds);
        $parameters['room_id'] = $roomId;
        $parameters['viewer_user_id'] = $actor->id;
        $reactionsSubquery = ReactionHydrator::correlatedSubquery(
            'room_message_reactions',
            'room_messages.id',
            ':viewer_user_id',
        );
        $statement = $this->pdo->prepare(<<<SQL
SELECT id,
       sender_id,
       message_type,
       body,
       edited_at,
       deleted_at,
       deleted_by,
       (
           SELECT json_agg(
               json_build_object(
                   'user_id', mm.mentioned_user_id,
                   'username', mu.username,
                   'broadcast', mm.broadcast
               )
               ORDER BY mm.id
           )
           FROM room_message_mentions mm
           JOIN users mu ON mu.id = mm.mentioned_user_id
           WHERE mm.message_id = room_messages.id
       ) AS mentions_json,
       {$reactionsSubquery} AS reactions_json
FROM room_messages
WHERE room_id = :room_id
  AND id IN ({$placeholders})
ORDER BY id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room-message mutation metadata.');
        }
        $statement->execute($parameters);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $result[] = $this->hydrateMetadata($row, $actor->id, $room->isMember());
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   id:int,
     *   body:?string,
     *   type:string,
     *   edited_at:?string,
     *   deleted:bool,
     *   deletion_kind:?string,
     *   can_edit:bool,
     *   can_delete:bool
     * }
     */
    public function edit(
        AuthenticatedUser $actor,
        int $messageId,
        string $bodyInput,
        string $ipAddress,
    ): array {
        $body = trim($bodyInput);
        if ($body === '') {
            throw new ApiException(400, 'empty_message', 'Message body cannot be empty.');
        }
        if (mb_strlen($body, 'UTF-8') > 4000) {
            throw new ApiException(400, 'message_too_long', 'Message body must not exceed 4000 characters.');
        }

        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedMessage($messageId);
            $room = $this->requireMutableRoom($actor, (int) $row['room_id']);
            $this->requireAuthorMutation($actor, $row);
            if ((string) $row['body'] === $body) {
                throw new ApiException(409, 'message_unchanged', 'The edited message is unchanged.');
            }

            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE room_messages
SET body = :body,
    edited_at = NOW(),
    edited_by = :edited_by
WHERE id = :id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare room-message edit.');
            }
            $statement->execute([
                'body' => $body,
                'edited_by' => $actor->id,
                'id' => $messageId,
            ]);

            $this->audit->log(
                $actor->id,
                'room.message_edited_by_author',
                'room_message',
                (string) $messageId,
                ['room_id' => $room->id],
                $ipAddress,
            );
            $message = (new MessageService($this->pdo))->storedMessage($messageId, $actor->id);
            $this->events->publish(
                type: 'room_message',
                payload: ['message' => $message],
                roomId: $room->id,
                actorUserId: $actor->id,
            );
            $this->pdo->commit();

            return $this->metadata($actor, $room->id, [$messageId])[0];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{
     *   id:int,
     *   body:?string,
     *   type:string,
     *   edited_at:?string,
     *   deleted:bool,
     *   deletion_kind:?string,
     *   can_edit:bool,
     *   can_delete:bool
     * }
     */
    public function deleteOwn(
        AuthenticatedUser $actor,
        int $messageId,
        string $ipAddress,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $row = $this->lockedMessage($messageId);
            $room = $this->requireMutableRoom($actor, (int) $row['room_id']);
            $this->requireAuthorMutation($actor, $row);

            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE room_messages
SET deleted_at = NOW(),
    deleted_by = :deleted_by
WHERE id = :id
SQL);
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare author room-message deletion.');
            }
            $statement->execute([
                'deleted_by' => $actor->id,
                'id' => $messageId,
            ]);

            $attachmentStatement = $this->pdo->prepare(<<<'SQL'
UPDATE attachments
SET deleted_at = NOW(),
    deleted_by = :deleted_by
WHERE message_id = :message_id
  AND deleted_at IS NULL
SQL);
            if ($attachmentStatement === false) {
                throw new RuntimeException('Unable to prepare author attachment deletion.');
            }
            $attachmentStatement->execute([
                'deleted_by' => $actor->id,
                'message_id' => $messageId,
            ]);

            $this->audit->log(
                $actor->id,
                'room.message_deleted_by_author',
                'room_message',
                (string) $messageId,
                ['room_id' => $room->id],
                $ipAddress,
            );
            $this->events->publish(
                type: 'message_deleted',
                payload: [
                    'message_id' => $messageId,
                    'room_id' => $room->id,
                    'deleted_by' => $actor->toArray(),
                    'deletion_kind' => 'author',
                ],
                roomId: $room->id,
                actorUserId: $actor->id,
            );
            $this->pdo->commit();

            return $this->metadata($actor, $room->id, [$messageId])[0];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function lockedMessage(int $messageId): array
    {
        if ($messageId < 1) {
            throw new ApiException(400, 'validation_error', 'message_id must be positive.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id,
       room_id,
       sender_id,
       message_type,
       body,
       edited_at,
       deleted_at,
       deleted_by
FROM room_messages
WHERE id = :id
FOR UPDATE
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare author room-message lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }

        return $row;
    }

    private function requireMutableRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        $room = $this->requireRoom($actor, $roomId);
        if (!$room->isMember()) {
            throw new ApiException(403, 'membership_required', 'Join the room before changing messages.');
        }
        (new RoomEligibility($this->rooms))->requireMinimumAge($actor, $room);

        return $room;
    }

    private function requireRoom(AuthenticatedUser $actor, int $roomId): Room
    {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }

        return $room;
    }

    /** @param array<string, mixed> $row */
    private function requireAuthorMutation(AuthenticatedUser $actor, array $row): void
    {
        if ($row['deleted_at'] !== null) {
            throw new ApiException(409, 'message_already_deleted', 'Message is already deleted.');
        }
        if ($row['sender_id'] === null || (int) $row['sender_id'] !== $actor->id) {
            throw new ApiException(403, 'message_author_required', 'Only the message author may change it.');
        }
        if (!in_array((string) $row['message_type'], ['text', 'emote', 'attachment'], true)) {
            throw new ApiException(409, 'message_not_mutable', 'This message type cannot be changed by its author.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   id:int,
     *   body:?string,
     *   type:string,
     *   edited_at:?string,
     *   deleted:bool,
     *   deletion_kind:?string,
     *   can_edit:bool,
     *   can_delete:bool,
     *   mentions:list<array{user_id:int, username:string, broadcast:bool}>,
     *   reactions:list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}>
     * }
     */
    private function hydrateMetadata(array $row, int $actorId, bool $isMember): array
    {
        $deleted = $row['deleted_at'] !== null;
        $owned = $row['sender_id'] !== null && (int) $row['sender_id'] === $actorId;
        $mutableType = in_array((string) $row['message_type'], ['text', 'emote', 'attachment'], true);
        $deletionKind = null;
        if ($deleted) {
            $deletionKind = $row['sender_id'] !== null
                && $row['deleted_by'] !== null
                && (int) $row['sender_id'] === (int) $row['deleted_by']
                ? 'author'
                : 'moderator';
        }

        return [
            'id' => (int) $row['id'],
            'body' => $deleted ? null : (string) $row['body'],
            'type' => (string) $row['message_type'],
            'edited_at' => $row['edited_at'] === null ? null : (string) $row['edited_at'],
            'deleted' => $deleted,
            'deletion_kind' => $deletionKind,
            'can_edit' => $isMember && $owned && $mutableType && !$deleted,
            'can_delete' => $isMember && $owned && $mutableType && !$deleted,
            'mentions' => $deleted ? [] : $this->hydrateMentions($row),
            'reactions' => ReactionHydrator::hydrateJson($row['reactions_json'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array{user_id:int, username:string, broadcast:bool}>
     */
    private function hydrateMentions(array $row): array
    {
        $encoded = $row['mentions_json'] ?? null;
        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        $decoded = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $mentions = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $mentions[] = [
                'user_id' => (int) $entry['user_id'],
                'username' => (string) $entry['username'],
                'broadcast' => $entry['broadcast'] === true,
            ];
        }

        return $mentions;
    }

    /**
     * @param list<int> $ids
     * @return array{string, array<string, int>}
     */
    private function idParameters(array $ids): array
    {
        $placeholders = [];
        $parameters = [];
        foreach ($ids as $index => $id) {
            $name = 'message_id_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        return [implode(', ', $placeholders), $parameters];
    }
}
