<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Moderation\ReportService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;

final class ModerationRetentionTest extends DatabaseTestCase
{
    public function testOpenEvidenceSurvivesCanonicalMessageRetention(): void
    {
        [$admin, $author, $reporter, $roomId] = $this->roomParticipants();
        $message = (new MessageService($this->pdo))->send($author, $roomId, 'Evidence must outlive the message.');
        $case = (new ReportService($this->pdo))->reportRoomMessage(
            $reporter,
            $message['id'],
            'harassment',
            null,
            '127.0.0.3',
        );

        $this->pdo->exec('UPDATE room_messages SET created_at = NOW() - INTERVAL \'10 days\'');
        $this->pdo->exec(<<<'SQL'
UPDATE system_settings
SET room_message_retention_days = 1,
    audit_retention_days = 0
WHERE id = 1
SQL);

        $result = (new CleanupService($this->pdo, $this->config))->run(false);
        self::assertSame(1, $result['room_messages']);
        self::assertSame(0, $this->rowCount('room_messages'));
        self::assertSame(1, $this->rowCount('moderation_cases'));
        self::assertSame(1, $this->rowCount('moderation_reports'));

        $detail = (new ReportService($this->pdo))->caseDetail($admin, $case['id']);
        self::assertSame('open', $detail['status']);
        self::assertSame('Evidence must outlive the message.', $detail['reports'][0]['evidence_body']);
    }

    public function testClosedEvidenceExpiresWithItsClosureAuditEntry(): void
    {
        [$admin, $author, $reporter, $roomId] = $this->roomParticipants();
        $message = (new MessageService($this->pdo))->send($author, $roomId, 'Closed evidence follows audit retention.');
        $reports = new ReportService($this->pdo);
        $case = $reports->reportRoomMessage(
            $reporter,
            $message['id'],
            'spam',
            null,
            '127.0.0.3',
        );
        $reports->resolve(
            $admin,
            $case['id'],
            'dismissed',
            'no_violation',
            null,
            '127.0.0.1',
        );

        $linkedAudit = $this->pdo->query(
            'SELECT closed_audit_id FROM moderation_cases WHERE id = ' . (int) $case['id'],
        );
        self::assertNotFalse($linkedAudit);
        $auditId = (int) $linkedAudit->fetchColumn();
        self::assertGreaterThan(0, $auditId);

        $ageAudit = $this->pdo->prepare(
            'UPDATE audit_log SET created_at = NOW() - INTERVAL \'10 days\' WHERE id = :id',
        );
        self::assertNotFalse($ageAudit);
        $ageAudit->execute(['id' => $auditId]);
        $this->pdo->exec('UPDATE system_settings SET audit_retention_days = 1 WHERE id = 1');

        $result = (new CleanupService($this->pdo, $this->config))->run(false);
        self::assertGreaterThanOrEqual(1, $result['audit_entries']);
        self::assertSame(0, $this->rowCount('moderation_cases'));
        self::assertSame(0, $this->rowCount('moderation_reports'));
    }

    /** @return array{0:\ChitChat\Auth\AuthenticatedUser,1:\ChitChat\Auth\AuthenticatedUser,2:\ChitChat\Auth\AuthenticatedUser,3:int} */
    private function roomParticipants(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $author = $auth->register('Author', 'another secure password', '127.0.0.2');
        $reporter = $auth->register('Reporter', 'different secure password', '127.0.0.3');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($author, $room->id, '127.0.0.2');

        return [$admin, $author, $reporter, $room->id];
    }

    private function rowCount(string $table): int
    {
        if (!in_array($table, ['room_messages', 'moderation_cases', 'moderation_reports'], true)) {
            self::fail('Unexpected table requested by retention test.');
        }
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
