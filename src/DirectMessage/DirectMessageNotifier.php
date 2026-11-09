<?php

declare(strict_types=1);

namespace ChitChat\DirectMessage;

use PDO;
use RuntimeException;

/** Called only inside the original text/attachment insertion transaction. */
final readonly class DirectMessageNotifier
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(int $messageId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Direct-message notifications require the message transaction.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_notifications (user_id, kind, context_json)
SELECT dm.recipient_user_id, 'direct_message_received',
       jsonb_build_object('message_kind', 'direct', 'message_id', dm.id, 'sender_user_id', dm.sender_user_id)
FROM direct_messages dm
JOIN users sender ON sender.id = dm.sender_user_id
JOIN users recipient ON recipient.id = dm.recipient_user_id
JOIN notification_preferences p ON p.user_id = recipient.id
 AND p.category = 'direct_message_received' AND p.push_enabled
WHERE dm.id = :id AND dm.deleted_at IS NULL AND dm.recipient_read_at IS NULL
 AND sender.account_state = 'active' AND sender.account_kind = 'member'
 AND recipient.account_state = 'active' AND recipient.account_kind = 'member'
 AND NOT EXISTS (
    SELECT 1 FROM user_bans b WHERE b.user_id IN (sender.id, recipient.id)
      AND b.revoked_at IS NULL AND b.starts_at <= NOW() AND (b.expires_at IS NULL OR b.expires_at > NOW())
 )
 AND NOT EXISTS (
    SELECT 1 FROM direct_message_blocks b
    WHERE (b.blocker_user_id = sender.id AND b.blocked_user_id = recipient.id)
       OR (b.blocker_user_id = recipient.id AND b.blocked_user_id = sender.id)
 )
 AND NOT EXISTS (
    SELECT 1 FROM user_mutes m WHERE m.user_id = sender.id AND m.room_id IS NULL
      AND m.lifted_at IS NULL AND (m.expires_at IS NULL OR m.expires_at > NOW())
 )
ON CONFLICT DO NOTHING
SQL);
        $statement->execute(['id' => $messageId]);
    }
}