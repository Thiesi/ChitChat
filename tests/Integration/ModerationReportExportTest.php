<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\PersonalDataExportService;
use ChitChat\Auth\AuthService;
use ChitChat\Moderation\ReportService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;

final class ModerationReportExportTest extends DatabaseTestCase
{
    public function testExportIncludesOnlyReportsSubmittedByTheAccount(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $author = $auth->register('Author', 'another secure password', '127.0.0.2');
        $reporter = $auth->register('Reporter', 'different secure password', '127.0.0.3');
        $otherReporter = $auth->register('OtherReporter', 'yet another secure password', '127.0.0.4');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($author, $room->id, '127.0.0.2');
        $messages = new MessageService($this->pdo);
        $first = $messages->send($author, $room->id, 'Evidence retained in my export');
        $second = $messages->send($author, $room->id, 'Evidence from somebody else’s report');

        $reports = new ReportService($this->pdo);
        $case = $reports->reportRoomMessage(
            $reporter,
            $first['id'],
            'privacy',
            'My retained report details',
            '127.0.0.3',
        );
        $reports->reportRoomMessage(
            $otherReporter,
            $second['id'],
            'spam',
            'Other reporter private details',
            '127.0.0.4',
        );
        $reports->resolve(
            $admin,
            $case['id'],
            'resolved',
            'user_warned',
            'Internal moderator resolution note',
            '127.0.0.1',
        );

        $export = (new PersonalDataExportService($this->pdo, $this->config))->export(
            $reporter,
            '192.0.2.20',
        );
        self::assertCount(1, $export['moderation']['reports_submitted']);
        $submitted = $export['moderation']['reports_submitted'][0];
        self::assertSame($case['id'], $submitted['case_id']);
        self::assertSame('privacy', $submitted['category']);
        self::assertSame('My retained report details', $submitted['details']);
        self::assertSame('Evidence retained in my export', $submitted['evidence_body']);
        self::assertSame('resolved', $submitted['case_status']);
        self::assertSame('user_warned', $submitted['resolution_code']);
        self::assertArrayNotHasKey('resolution_note', $submitted);
        self::assertArrayNotHasKey('assigned_to', $submitted);

        $encoded = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Other reporter private details', $encoded);
        self::assertStringNotContainsString('Evidence from somebody else’s report', $encoded);
        self::assertStringNotContainsString('Internal moderator resolution note', $encoded);

        $auditStatement = $this->pdo->query(<<<'SQL'
SELECT metadata_json
FROM audit_log
WHERE action = 'account.personal_data_exported'
ORDER BY id DESC
LIMIT 1
SQL);
        self::assertNotFalse($auditStatement);
        $metadata = json_decode((string) $auditStatement->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(1, $metadata['counts']['moderation_reports_submitted']);
    }
}
