<?php

declare(strict_types=1);

namespace ChitChat\Mentions;

use PDO;
use RuntimeException;

final class MentionNotifier
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<array{user_id:int, broadcast:bool}> $mentions */
    public function recordRoomMentions(
        int $messageId,
        int $roomId,
        string $roomName,
        int $senderUserId,
        string $senderUsername,
        array $mentions,
    ): void {
        if ($mentions === []) {
            return;
        }

        $mentionStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO room_message_mentions (message_id, mentioned_user_id, broadcast)
VALUES (:message_id, :mentioned_user_id, :broadcast)
ON CONFLICT (message_id, mentioned_user_id) DO NOTHING
SQL);
        if ($mentionStatement === false) {
            throw new RuntimeException('Unable to prepare room mention insert.');
        }

        foreach ($mentions as $mention) {
            $mentionStatement->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $mentionStatement->bindValue(':mentioned_user_id', $mention['user_id'], PDO::PARAM_INT);
            $mentionStatement->bindValue(':broadcast', $mention['broadcast'], PDO::PARAM_BOOL);
            $mentionStatement->execute();

            $this->notify($mention['user_id'], [
                'message_kind' => 'room',
                'message_id' => $messageId,
                'room_id' => $roomId,
                'room_name' => $roomName,
                'broadcast' => $mention['broadcast'],
                'sender_user_id' => $senderUserId,
                'sender_username' => $senderUsername,
            ]);
        }
    }

    /** @param list<array{user_id:int, broadcast:bool}> $mentions */
    public function recordDirectMessageMentions(
        int $messageId,
        int $senderUserId,
        string $senderUsername,
        array $mentions,
    ): void {
        if ($mentions === []) {
            return;
        }

        $mentionStatement = $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_message_mentions (message_id, mentioned_user_id)
VALUES (:message_id, :mentioned_user_id)
ON CONFLICT (message_id, mentioned_user_id) DO NOTHING
SQL);
        if ($mentionStatement === false) {
            throw new RuntimeException('Unable to prepare direct-message mention insert.');
        }

        foreach ($mentions as $mention) {
            $mentionStatement->execute([
                'message_id' => $messageId,
                'mentioned_user_id' => $mention['user_id'],
            ]);

            $this->notify($mention['user_id'], [
                'message_kind' => 'direct',
                'message_id' => $messageId,
                'broadcast' => false,
                'sender_user_id' => $senderUserId,
                'sender_username' => $senderUsername,
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function notify(int $userId, array $context): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_notifications (user_id, kind, context_json)
VALUES (:user_id, 'mentioned', CAST(:context AS jsonb))
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mention notification insert.');
        }

        $statement->execute([
            'user_id' => $userId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
        ]);
    }
}
