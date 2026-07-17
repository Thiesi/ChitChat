<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\PersonalDataExportService;
use ChitChat\Auth\AuthService;
use ChitChat\Audit\AuditLogger;

final class PersonalDataExportServiceTest extends DatabaseTestCase
{
    public function testExportIncludesOwnedAndVisibleDataWithoutSecretsOrOtherUsersPrivateState(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $actor = $auth->register('Exporter', 'A sufficiently long exporter password', '127.0.0.1', '1985-05-04');
        $peer = $auth->register('Peer', 'A sufficiently long peer password', '127.0.0.2');
        $outsider = $auth->register('Outsider', 'A sufficiently long outsider password', '127.0.0.3');

        $this->pdo->prepare(<<<'SQL'
INSERT INTO rooms (room_key, name, info_line, visibility, minimum_age, created_by)
VALUES ('export-room', 'Export room', 'Personal export test', 'public', 0, :created_by)
SQL)->execute(['created_by' => $actor->id]);
        $roomId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(<<<'SQL'
INSERT INTO room_members (room_id, user_id, role)
VALUES (:room_id, :actor_id, 'owner'), (:room_id, :peer_id, 'member')
SQL)->execute([
            'room_id' => $roomId,
            'actor_id' => $actor->id,
            'peer_id' => $peer->id,
        ]);

        $this->pdo->prepare(<<<'SQL'
INSERT INTO room_messages (room_id, sender_id, message_type, body)
VALUES (:room_id, :sender_id, 'text', 'Original exported room body')
RETURNING id
SQL)->execute(['room_id' => $roomId, 'sender_id' => $actor->id]);
        $roomMessageId = (int) $this->pdo->query('SELECT MAX(id) FROM room_messages')->fetchColumn();
        $this->pdo->prepare(<<<'SQL'
UPDATE room_messages
SET body = 'Edited exported room body', edited_at = NOW(), edited_by = :actor_id
WHERE id = :message_id
SQL)->execute(['actor_id' => $actor->id, 'message_id' => $roomMessageId]);

        $this->pdo->prepare(<<<'SQL'
INSERT INTO room_messages (room_id, sender_id, message_type, body)
VALUES (:room_id, :sender_id, 'text', 'Outsider room body')
SQL)->execute(['room_id' => $roomId, 'sender_id' => $outsider->id]);

        $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_messages (sender_user_id, recipient_user_id, body)
VALUES (:actor_id, :peer_id, 'Actor direct message')
RETURNING id
SQL)->execute(['actor_id' => $actor->id, 'peer_id' => $peer->id]);
        $actorDirectMessageId = (int) $this->pdo->query('SELECT MAX(id) FROM direct_messages')->fetchColumn();
        $this->pdo->prepare(<<<'SQL'
UPDATE direct_messages
SET body = 'Actor edited direct message', edited_at = NOW(), edited_by = :actor_id
WHERE id = :message_id
SQL)->execute(['actor_id' => $actor->id, 'message_id' => $actorDirectMessageId]);

        $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_messages (sender_user_id, recipient_user_id, body)
VALUES (:peer_id, :actor_id, 'Peer direct message')
SQL)->execute(['peer_id' => $peer->id, 'actor_id' => $actor->id]);
        $peerDirectMessageId = (int) $this->pdo->query('SELECT MAX(id) FROM direct_messages')->fetchColumn();
        $this->pdo->prepare(<<<'SQL'
UPDATE direct_messages
SET body = 'Peer edited direct message', edited_at = NOW(), edited_by = :peer_id
WHERE id = :message_id
SQL)->execute(['peer_id' => $peer->id, 'message_id' => $peerDirectMessageId]);

        $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_messages (sender_user_id, recipient_user_id, body)
VALUES (:peer_id, :outsider_id, 'Private message outside the export')
SQL)->execute(['peer_id' => $peer->id, 'outsider_id' => $outsider->id]);

        $this->pdo->prepare(<<<'SQL'
INSERT INTO direct_message_blocks (blocker_user_id, blocked_user_id)
VALUES (:actor_id, :peer_id), (:outsider_id, :actor_id)
SQL)->execute([
            'actor_id' => $actor->id,
            'peer_id' => $peer->id,
            'outsider_id' => $outsider->id,
        ]);

        (new AuditLogger($this->pdo))->log(
            actorUserId: $actor->id,
            action: 'test.actor_activity',
            subjectType: 'user',
            subjectId: (string) $peer->id,
            metadata: ['visible' => true],
            ipAddress: '192.0.2.10',
        );
        (new AuditLogger($this->pdo))->log(
            actorUserId: $peer->id,
            action: 'test.peer_activity',
            subjectType: 'user',
            subjectId: (string) $actor->id,
            metadata: ['must_not_leak' => true],
            ipAddress: '192.0.2.11',
        );

        $export = (new PersonalDataExportService($this->pdo, $this->config))->export(
            $actor,
            '192.0.2.12',
        );

        self::assertSame('chitchat-personal-data-export', $export['format']['name']);
        self::assertSame(1, $export['format']['version']);
        self::assertSame('Exporter', $export['account']['username']);
        self::assertSame('1985-05-04', $export['account']['birth_date']);
        self::assertArrayNotHasKey('password_hash', $export['account']);

        self::assertCount(1, $export['rooms']['created']);
        self::assertSame('Edited exported room body', $export['rooms']['authored_messages'][0]['body']);
        self::assertCount(1, $export['rooms']['authored_message_revisions']);
        self::assertSame('Original exported room body', $export['rooms']['authored_message_revisions'][0]['body_before']);

        self::assertCount(2, $export['direct_messages']['messages']);
        self::assertCount(1, $export['direct_messages']['authored_message_revisions']);
        self::assertSame('Actor direct message', $export['direct_messages']['authored_message_revisions'][0]['body_before']);
        self::assertCount(1, $export['direct_messages']['blocks_created']);
        self::assertSame('Peer', $export['direct_messages']['blocks_created'][0]['blocked_user']['username']);

        self::assertSame(['test.actor_activity'], array_column($export['activity'], 'action'));
        $encoded = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private message outside the export', $encoded);
        self::assertStringNotContainsString('Peer direct message', $encoded);
        self::assertStringNotContainsString('must_not_leak', $encoded);
        self::assertStringNotContainsString('192.0.2.11', $encoded);
        self::assertStringNotContainsString('storage_key', $encoded);

        $exportAudits = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'account.personal_data_exported'",
        )->fetchColumn();
        self::assertSame(1, $exportAudits);
    }
}
