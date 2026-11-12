<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\UsernameChangeService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\StreamCursor;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use PDO;

/** The stream cursor moves past events the account can't see, but never past an unsettled one (#281). */
final class StreamCursorTest extends DatabaseTestCase
{
    private const PASSWORD = 'Stream-Cursor-Test-Aa1!';

    private AuthenticatedUser $owner;
    private AuthenticatedUser $member;
    private int $roomId;
    private int $otherRoomId;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = new AuthService($this->pdo, $this->config);
        $this->owner = $auth->register('Owner', self::PASSWORD, '127.0.0.1');
        $this->member = $auth->register('Member', self::PASSWORD, '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $this->roomId = $rooms->create($this->owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1')->id;
        $this->otherRoomId = $rooms->create($this->owner, 'other', 'Other', '', 'public', 0, 0, '127.0.0.1')->id;
        $rooms->join($this->member, $this->roomId, '127.0.0.2');
    }

    public function testTheCursorMovesPastInvisibleEventsAndThenScansOnlyNewOnes(): void
    {
        $start = $this->newestEventId();
        $this->invisibleEvents(1200);
        $settled = $this->newestEventId();

        $poll = (new StreamCursor($this->pdo))->poll($this->member, $start, 100, true);
        self::assertTrue($poll['checked']);
        self::assertSame([], $poll['events']);
        self::assertSame($settled, $poll['cursor'], 'Moved to the settled horizon.');

        $sent = (new MessageService($this->pdo))->send($this->owner, $this->roomId, 'After the skip');
        $next = (new StreamCursor($this->pdo))->poll($this->member, $poll['cursor'], 100, true);
        self::assertFalse($next['checked'], 'Up to date: no lock check.');
        self::assertSame([$sent['id']], array_map(static fn ($event) => $event->payload['message']['id'] ?? null, $next['events']));
    }

    public function testNoCheckWhenItCannotHelpOrIsNotAllowed(): void
    {
        $start = $this->newestEventId();
        $this->invisibleEvents(10);
        $near = (new StreamCursor($this->pdo))->poll($this->member, $start, 100, true);
        self::assertFalse($near['checked'], 'Fewer than SKIP_GAP ids behind.');
        self::assertSame($start, $near['cursor']);

        $this->invisibleEvents(1200);
        $denied = (new StreamCursor($this->pdo))->poll($this->member, $start, 100, false);
        self::assertFalse($denied['checked']);
        self::assertSame($start, $denied['cursor'], 'The stream did not allow a check this poll.');
    }

    public function testAnEventStillBeingPublishedIsNeverSkippedAndArrivesAfterCommit(): void
    {
        $start = $this->newestEventId();
        $publisher = Database::connect($this->config);
        $publisher->beginTransaction();
        try {
            // A visible event whose transaction stays open.
            $publisher->exec("INSERT INTO realtime_events (event_type, room_id, payload) VALUES ('room_broadcast', {$this->roomId}, '{\"held\":true}'::jsonb)");
            $held = (int) $publisher->query('SELECT MAX(id) FROM realtime_events')?->fetchColumn();
            // Many later events the member can't see commit meanwhile.
            $this->invisibleEvents(1200);

            $poll = (new StreamCursor($this->pdo))->poll($this->member, $start, 100, true);
            self::assertTrue($poll['checked']);
            self::assertLessThan($held, $poll['cursor'], 'Never moved past the open transaction\'s event.');
            $publisher->commit();
        } finally {
            if ($publisher->inTransaction()) {
                $publisher->rollBack();
            }
        }

        $after = (new StreamCursor($this->pdo))->poll($this->member, $poll['cursor'], 100, true);
        self::assertContains($held, array_map(static fn ($event) => $event->id, $after['events']), 'Delivered once committed.');
    }

    public function testAnOpenDeleteOnTheEventTableAlsoBlocksTheSkip(): void
    {
        $start = $this->newestEventId();
        $this->invisibleEvents(1200);
        $maintenance = Database::connect($this->config);
        $maintenance->beginTransaction();
        try {
            $maintenance->exec('DELETE FROM realtime_events WHERE id = (SELECT MIN(id) FROM realtime_events)');
            $poll = (new StreamCursor($this->pdo))->poll($this->member, $start, 100, true);
            self::assertTrue($poll['checked']);
            self::assertSame($start, $poll['cursor']);
        } finally {
            $maintenance->rollBack();
        }
    }

    public function testAReplayedMessageCarriesTheSendersCurrentNameAfterAModeratorReplacedIt(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $moderator = $auth->register('Moderator', self::PASSWORD, '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        $moderator = (new UserRepository($this->pdo))->findAuthenticatedById($moderator->id) ?? self::fail('reload');
        $rude = $auth->register('Rude.Name', self::PASSWORD, '127.0.0.4');
        (new RoomService($this->pdo))->join($rude, $this->roomId, '127.0.0.4');
        $cursor = $this->newestEventId();
        $messages = new MessageService($this->pdo);
        $quoted = $messages->send($rude, $this->roomId, 'Hello from me');
        $messages->send($this->owner, $this->roomId, 'Replying', $quoted['id']);
        (new DirectMessageService($this->pdo))->send($rude, $this->member->id, 'A private hello');
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $rude->id, 'Offensive', null, '127.0.0.3');

        $stream = json_encode(array_map(
            static fn ($event) => ['type' => $event->type, 'payload' => $event->payload],
            (new EventRepository($this->pdo))->visibleAfter($this->member, $cursor, 100),
        ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Rude.Name', $stream, 'Not in the message, the quote or the DM.');
        self::assertStringContainsString('Hello from me', $stream);
    }

    private function invisibleEvents(int $count): void
    {
        $this->pdo->exec(<<<SQL
INSERT INTO realtime_events (event_type, room_id, payload)
SELECT 'room_broadcast', {$this->otherRoomId}, jsonb_build_object('n', n) FROM generate_series(1, {$count}) n
SQL);
    }

    private function newestEventId(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) FROM realtime_events')?->fetchColumn();
    }
}
