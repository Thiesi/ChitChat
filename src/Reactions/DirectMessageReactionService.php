<?php

declare(strict_types=1);

namespace ChitChat\Reactions;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;
use PDO;
use RuntimeException;

final class DirectMessageReactionService
{
    private readonly EventRepository $events;

    public function __construct(private readonly PDO $pdo)
    {
        $this->events = new EventRepository($pdo);
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    public function add(AuthenticatedUser $actor, int $messageId, string $emoji): array
    {
        $emoji = ReactionVocabulary::require($emoji);
        [$otherUserId, $deletedAt] = $this->requireMessage($actor, $messageId);
        if ($deletedAt !== null) {
            throw new ApiException(409, 'message_already_deleted', 'Message is already deleted.');
        }

        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_message_reactions (message_id, user_id, emoji)
VALUES (:message_id, :user_id, :emoji)
ON CONFLICT (message_id, user_id, emoji) DO NOTHING
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message reaction add.');
        }
        $statement->execute(['message_id' => $messageId, 'user_id' => $actor->id, 'emoji' => $emoji]);

        return $this->publishAndReturn($messageId, $actor->id, $otherUserId);
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    public function remove(AuthenticatedUser $actor, int $messageId, string $emoji): array
    {
        $emoji = ReactionVocabulary::require($emoji);
        [$otherUserId] = $this->requireMessage($actor, $messageId);

        $statement = $this->pdo->prepare(<<<'SQL'
DELETE FROM direct_message_reactions
WHERE message_id = :message_id AND user_id = :user_id AND emoji = :emoji
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message reaction removal.');
        }
        $statement->execute(['message_id' => $messageId, 'user_id' => $actor->id, 'emoji' => $emoji]);

        return $this->publishAndReturn($messageId, $actor->id, $otherUserId);
    }

    /** @return array{0:int, 1:?string} the other participant's user id, and deleted_at */
    private function requireMessage(AuthenticatedUser $actor, int $messageId): array
    {
        if ($messageId < 1) {
            throw new ApiException(400, 'validation_error', 'message_id must be positive.');
        }

        $statement = $this->pdo->prepare(
            'SELECT sender_user_id, recipient_user_id, deleted_at FROM direct_messages WHERE id = :id',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message reaction lookup.');
        }
        $statement->execute(['id' => $messageId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }

        $senderId = (int) $row['sender_user_id'];
        $recipientId = (int) $row['recipient_user_id'];
        if ($actor->id !== $senderId && $actor->id !== $recipientId) {
            throw new ApiException(404, 'message_not_found', 'Message not found.');
        }
        $otherUserId = $actor->id === $senderId ? $recipientId : $senderId;

        return [$otherUserId, $row['deleted_at'] === null ? null : (string) $row['deleted_at']];
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    private function publishAndReturn(int $messageId, int $actorId, int $otherUserId): array
    {
        $reactionsForActor = $this->reactionsFor($messageId, $actorId);
        $reactionsForOther = $this->reactionsFor($messageId, $otherUserId);

        $this->events->publish(
            type: 'message_reaction_changed',
            payload: ['message_kind' => 'direct', 'message_id' => $messageId, 'reactions' => $reactionsForActor],
            targetUserId: $actorId,
            actorUserId: $actorId,
        );
        $this->events->publish(
            type: 'message_reaction_changed',
            payload: ['message_kind' => 'direct', 'message_id' => $messageId, 'reactions' => $reactionsForOther],
            targetUserId: $otherUserId,
            actorUserId: $actorId,
        );

        return $reactionsForActor;
    }

    /** @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}> */
    private function reactionsFor(int $messageId, int $viewerUserId): array
    {
        $subquery = ReactionHydrator::correlatedSubquery(
            'direct_message_reactions',
            ':message_id',
            ':viewer_user_id',
        );
        $statement = $this->pdo->prepare("SELECT {$subquery} AS reactions_json");
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare direct-message reaction summary.');
        }
        $statement->execute(['message_id' => $messageId, 'viewer_user_id' => $viewerUserId]);
        $row = $statement->fetch();

        return ReactionHydrator::hydrateJson(is_array($row) ? ($row['reactions_json'] ?? null) : null);
    }
}
