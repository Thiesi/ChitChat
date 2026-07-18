<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ReportService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;

final class ReportServiceTest extends DatabaseTestCase
{
    public function testRoomReportsPreserveExactEvidenceAndRespectRoomModeratorScope(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $author = $auth->register('Author', 'another secure password', '127.0.0.2');
        $reporter = $auth->register('Reporter', 'different secure password', '127.0.0.3');
        $moderator = $auth->register('Moderator', 'yet another secure password', '127.0.0.4');
        $outsider = $auth->register('Outsider', 'one more secure password', '127.0.0.5');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($admin, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($author, $room->id, '127.0.0.2');
        $rooms->join($moderator, $room->id, '127.0.0.4');
        $rooms->setRole($admin, $room->id, $moderator->id, 'moderator', '127.0.0.1');

        $message = (new MessageService($this->pdo))->send($author, $room->id, 'Original report evidence');
        $reports = new ReportService($this->pdo);
        $case = $reports->reportRoomMessage(
            $reporter,
            $message['id'],
            'harassment',
            'Private reporter explanation',
            '127.0.0.3',
        );

        (new RoomMessageMutationService($this->pdo))->edit(
            $author,
            $message['id'],
            'Edited after the report',
            '127.0.0.2',
        );

        $detail = $reports->caseDetail($moderator, $case['id']);
        self::assertSame('room', $detail['message_kind']);
        self::assertSame('Original report evidence', $detail['reports'][0]['evidence_body']);
        self::assertSame('Private reporter explanation', $detail['reports'][0]['details']);
        self::assertSame($room->id, $detail['room']['id']);
        self::assertSame($author->id, $detail['subject']['id']);

        $queue = $reports->cases($moderator);
        self::assertCount(1, $queue['cases']);
        self::assertSame($case['id'], $queue['cases'][0]['id']);

        try {
            $reports->caseDetail($outsider, $case['id']);
            self::fail('Expected an unrelated participant to be denied the moderation case.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        try {
            $reports->reportRoomMessage($reporter, $message['id'], 'spam', null, '127.0.0.3');
            self::fail('Expected duplicate report rejection.');
        } catch (ApiException $exception) {
            self::assertSame('message_already_reported', $exception->errorCode);
        }

        try {
            $reports->reportRoomMessage($author, $message['id'], 'other', null, '127.0.0.2');
            self::fail('Expected self-report rejection.');
        } catch (ApiException $exception) {
            self::assertSame('message_not_reportable', $exception->errorCode);
        }

        $claimed = $reports->claim($moderator, $case['id'], true, '127.0.0.4');
        self::assertSame('in_review', $claimed['status']);
        self::assertSame($moderator->id, $claimed['assigned_to']['id']);

        $resolved = $reports->resolve(
            $moderator,
            $case['id'],
            'resolved',
            'content_removed',
            'Removed through ordinary room moderation.',
            '127.0.0.4',
        );
        self::assertSame('resolved', $resolved['status']);
        self::assertSame('content_removed', $resolved['resolution_code']);

        $audit = $this->pdo->query(<<<'SQL'
SELECT metadata_json::text
FROM audit_log
WHERE action IN ('moderation.report_created', 'moderation.case_claimed', 'moderation.case_closed')
ORDER BY id
SQL);
        self::assertNotFalse($audit);
        $metadata = implode("\n", array_map('strval', $audit->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertStringNotContainsString('Original report evidence', $metadata);
        self::assertStringNotContainsString('Private reporter explanation', $metadata);
    }

    public function testDirectMessageReportsExposeOnlySubmittedSnapshotsToGlobalModerators(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $sender = $auth->register('Sender', 'another secure password', '127.0.0.2');
        $recipient = $auth->register('Recipient', 'different secure password', '127.0.0.3');
        $outsider = $auth->register('Outsider', 'yet another secure password', '127.0.0.4');
        $roomModeratorBase = $auth->register('RoomModerator', 'one more secure password', '127.0.0.5');
        $globalModerator = new AuthenticatedUser(
            id: $outsider->id,
            username: $outsider->username,
            roles: ['global_moderator'],
            sessionVersion: $outsider->sessionVersion,
        );

        $roomService = new RoomService($this->pdo);
        $room = $roomService->create($admin, 'moderated', 'Moderated', '', 'public', 0, 0, '127.0.0.1');
        $roomService->join($roomModeratorBase, $room->id, '127.0.0.5');
        $roomService->setRole($admin, $room->id, $roomModeratorBase->id, 'moderator', '127.0.0.1');

        $direct = new DirectMessageService($this->pdo);
        $reported = $direct->send($sender, $recipient->id, 'Exact direct-message evidence');
        $direct->send($sender, $recipient->id, 'Unrelated surrounding direct-message history');

        $reports = new ReportService($this->pdo);
        $case = $reports->reportDirectMessage(
            $recipient,
            $reported['id'],
            'threats',
            'Please review this exact message.',
            '127.0.0.3',
        );

        try {
            $reports->caseDetail($roomModeratorBase, $case['id']);
            self::fail('Expected a room-only moderator to be denied a direct-message report.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        $detail = $reports->caseDetail($globalModerator, $case['id']);
        self::assertSame('direct', $detail['message_kind']);
        self::assertSame('Exact direct-message evidence', $detail['reports'][0]['evidence_body']);
        self::assertStringNotContainsString(
            'Unrelated surrounding direct-message history',
            json_encode($detail, JSON_THROW_ON_ERROR),
        );

        try {
            $reports->reportDirectMessage($sender, $reported['id'], 'other', null, '127.0.0.2');
            self::fail('Expected the sender to be unable to report their own direct message.');
        } catch (ApiException $exception) {
            self::assertSame('message_not_reportable', $exception->errorCode);
        }

        try {
            $reports->reportDirectMessage($roomModeratorBase, $reported['id'], 'other', null, '127.0.0.5');
            self::fail('Expected a non-participant to be unable to report a direct message.');
        } catch (ApiException $exception) {
            self::assertSame('message_not_found', $exception->errorCode);
        }

        $dismissed = $reports->resolve(
            $globalModerator,
            $case['id'],
            'dismissed',
            'no_violation',
            null,
            '127.0.0.4',
        );
        self::assertSame('dismissed', $dismissed['status']);
        self::assertSame($globalModerator->id, $dismissed['resolved_by']['id']);
    }
}
