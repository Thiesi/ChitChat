<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Admin\AdminService;
use ChitChat\Admin\UserDirectoryService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationService;
use ChitChat\Moderation\ReportService;
use ChitChat\Moderation\UserReportService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use Closure;

final class StewardingDataTest extends DatabaseTestCase
{
    private AuthenticatedUser $root;
    private AuthenticatedUser $subject;
    private AuthenticatedUser $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        $auth = new AuthService($this->pdo, $this->config);
        $this->root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $this->subject = $auth->register('AnnStaff', 'a very secure password', '127.0.0.2');
        $this->reporter = $auth->register('AnnOther', 'a very secure password', '127.0.0.3');
    }

    public function testFiltersCountsRestrictionDetailsAndEmptyPagesShareTheSearchScope(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $muted = $auth->register('AnnMuted', 'a very secure password', '127.0.0.4');
        $closing = $auth->register('AnnClosing', 'a very secure password', '127.0.0.5');
        $literal = $auth->register('ann_member', 'a very secure password', '127.0.0.6');
        $auth->register('annxmember', 'a very secure password', '127.0.0.7');
        $this->pdo->exec("INSERT INTO user_roles (user_id,role) VALUES ({$this->subject->id},'chat_admin')");
        $this->pdo->exec("INSERT INTO users (username,username_canonical,password_hash,account_kind,guest_expires_at,guest_last_seen_at)
            VALUES ('AnnGuest','annguest','unused','guest',NOW()+INTERVAL '1 hour',NOW())");
        (new ModerationService($this->pdo))->ban($this->root, $this->reporter->id, 'Recorded ban reason', null, '127.0.0.1');
        $rooms = new RoomService($this->pdo);
        $a = $rooms->create($this->root, 'room-a', 'Room A', '', 'public', 0, 0, '127.0.0.1');
        $b = $rooms->create($this->root, 'room-b', 'Room B', '', 'public', 0, 0, '127.0.0.1');
        $this->pdo->exec("INSERT INTO user_mutes (user_id,room_id,expires_at) VALUES
            ({$muted->id},NULL,NULL),({$muted->id},{$a->id},NULL),({$muted->id},{$b->id},NOW()+INTERVAL '1 hour')");
        (new AccountClosureService($this->pdo, $this->config))->request($closing, '127.0.0.5');
        $directory = new UserDirectoryService($this->pdo);
        $counts = ['all' => 6, 'staff' => 1, 'banned' => 1, 'muted' => 1, 'closing' => 1];
        foreach (['staff' => $this->subject->id, 'banned' => $this->reporter->id, 'muted' => $muted->id, 'closing' => $closing->id] as $filter => $id) {
            $page = $directory->page($this->root, 'Ann', filter: $filter);
            self::assertSame([$id], array_column($page['users'], 'id'));
            self::assertSame($counts, $page['counts']);
            self::assertNull($page['next_cursor']);
        }
        $ban = $directory->page($this->root, filter: 'banned')['users'][0]['active_ban'];
        self::assertSame('Recorded ban reason', $ban['reason']);
        self::assertSame(['id' => $this->root->id, 'username' => 'Root'], $ban['created_by']);
        self::assertNotEmpty($ban['starts_at']);
        $mute = $directory->page($this->root, filter: 'muted')['users'][0]['active_mute'];
        self::assertSame('everywhere', $mute['scope']);
        self::assertNull($mute['room']);
        self::assertSame(2, $mute['room_mute_count']);
        $this->pdo->exec("UPDATE user_mutes SET lifted_at = NOW() WHERE user_id = {$muted->id} AND room_id IS NULL");
        $mute = $directory->page($this->root, filter: 'muted')['users'][0]['active_mute'];
        self::assertSame(['id' => $b->id, 'name' => 'Room B'], $mute['room'], 'Finite soonest-ending mute precedes permanent room mutes.');
        self::assertSame(2, $mute['room_mute_count']);
        self::assertNotEmpty($directory->page($this->root, filter: 'closing')['users'][0]['closing']['purge_at']);
        self::assertSame([$literal->id], array_column($directory->page($this->root, 'ann_')['users'], 'id'));
        $empty = $directory->page($this->root, 'Ann', afterId: 999999, filter: 'staff');
        self::assertSame([], $empty['users']);
        self::assertSame($counts, $empty['counts']);
        self::assertNull($empty['next_cursor']);
        self::assertSame(['all' => 0, 'staff' => 0, 'banned' => 0, 'muted' => 0, 'closing' => 0],
            $directory->page($this->root, 'Nobody')['counts']);
    }

    public function testExpiredRevokedFutureAndLiftedRestrictionsAreNotActive(): void
    {
        $id = $this->subject->id;
        $root = $this->root->id;
        $this->pdo->exec("INSERT INTO user_bans (user_id,created_by,starts_at,expires_at,revoked_at) VALUES
            ($id,$root,NOW()-INTERVAL '2 days',NOW()-INTERVAL '1 day',NULL),
            ($id,$root,NOW(),NULL,NOW()),
            ($id,$root,NOW()+INTERVAL '1 day',NULL,NULL)");
        $this->pdo->exec("INSERT INTO user_mutes (user_id,created_at,expires_at,lifted_at) VALUES
            ($id,NOW()-INTERVAL '2 days',NOW()-INTERVAL '1 day',NULL),($id,NOW(),NULL,NOW())");
        $page = (new UserDirectoryService($this->pdo))->page($this->root, 'AnnStaff');
        self::assertNull($page['users'][0]['active_ban']);
        self::assertNull($page['users'][0]['active_mute']);
        self::assertSame(0, $page['counts']['banned']);
        self::assertSame(0, $page['counts']['muted']);
    }

    public function testCompositeCursorTraversesTiesAndNeverSignedInUsersAndPreservesLegacyIdOrder(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $older = $auth->register('Older', 'a very secure password', '127.0.0.4');
        $never = $auth->register('Never', 'a very secure password', '127.0.0.5');
        $alsoNever = $auth->register('AlsoNever', 'a very secure password', '127.0.0.6');
        $this->pdo->exec("UPDATE users SET last_login_at = '2026-01-01T10:10:10.123456Z' WHERE id IN ({$this->subject->id},{$this->reporter->id})");
        $this->pdo->exec("UPDATE users SET last_login_at = '2025-01-01T00:00:00Z' WHERE id = {$this->root->id}");
        $this->pdo->exec("UPDATE users SET last_login_at = '2024-01-01T00:00:00Z' WHERE id = {$older->id}");
        $directory = new UserDirectoryService($this->pdo);
        $ids = [];
        $cursor = null;
        do {
            $page = $directory->page($this->root, limit: 1, sort: 'last_sign_in', cursor: $cursor);
            self::assertSame(6, $page['counts']['all'], 'Counts ignore pagination.');
            $ids = [...$ids, ...array_column($page['users'], 'id')];
            $cursor = $page['next_cursor'];
        } while ($cursor !== null);
        self::assertSame([$this->reporter->id,$this->subject->id,$this->root->id,$older->id,$alsoNever->id,$never->id], $ids);
        $first = $directory->page($this->root, limit: 2);
        $next = $directory->page($this->root, limit: 2, cursor: $first['next_cursor']);
        self::assertSame(array_column((new AdminService($this->pdo))->listUsers($this->root, afterId: $this->subject->id, limit: 2), 'id'),
            array_column($next['users'], 'id'));
    }

    public function testInvalidOrCrossScopeCursorsAndUnauthorizedStaffRolesAreRejected(): void
    {
        $directory = new UserDirectoryService($this->pdo);
        $cursor = $directory->page($this->root, 'Ann', limit: 1)['next_cursor'];
        self::assertIsString($cursor);
        foreach ([
            fn () => $directory->page($this->root, cursor: 'not!base64'),
            fn () => $directory->page($this->root, cursor: $cursor),
            fn () => $directory->page($this->root, 'Ann', filter: 'staff', cursor: $cursor),
            fn () => $directory->page($this->root, 'Ann', sort: 'last_sign_in', cursor: $cursor),
            fn () => $directory->page($this->root, 'Ann', afterId: 1, cursor: $cursor),
            fn () => $directory->page($this->root, sort: 'last_sign_in', afterId: 1),
            fn () => $directory->page($this->root, filter: 'everything'),
            fn () => $directory->page($this->root, sort: 'DROP TABLE users'),
            fn () => $directory->page($this->root, limit: 101),
        ] as $call) $this->reject(400, $call);
        $payload = json_decode(base64_decode(strtr($cursor, '-_', '+/'), true), true, 8, JSON_THROW_ON_ERROR);
        foreach (['0000-01-01T00:00:00.000000+00:00', '2026-02-30T00:00:00.000000+00:00', str_repeat('x', 32), "\0" . str_repeat('x', 31), false] as $time) {
            $payload['last_login_at'] = $time;
            $tampered = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
            $this->reject(400, fn () => $directory->page($this->root, 'Ann', cursor: $tampered));
        }

        foreach ([[], ['chat_admin'], ['global_moderator']] as $roles) {
            $actor = new AuthenticatedUser($this->subject->id, $this->subject->username, $roles, 1);
            $this->reject(403, fn () => $directory->page($actor));
            $this->reject(403, fn () => (new AdminService($this->pdo))->auditEntries($actor, userId: $this->reporter->id));
        }
        $admin = new AuthenticatedUser($this->subject->id, $this->subject->username, ['admin'], 1);
        self::assertNotEmpty($directory->page($admin)['users']);
    }

    public function testAccountAuditMatchesActorOrUserSubjectWithoutBroadeningOrLeakingSecrets(): void
    {
        $this->pdo->exec('DELETE FROM audit_log');
        $logger = new AuditLogger($this->pdo);
        $logger->log($this->subject->id, 'test.actor', 'room', '99', ['safe' => true], '127.0.0.2');
        $logger->log($this->root->id, 'test.target', 'user', (string) $this->subject->id, [], '127.0.0.1');
        $logger->log($this->subject->id, 'test.both', 'user', (string) $this->subject->id, [], '127.0.0.2');
        $logger->log($this->root->id, 'test.room', 'room', (string) $this->subject->id, [], '127.0.0.1');
        $logger->log($this->root->id, 'test.metadata', 'user', (string) $this->reporter->id, ['subject_user_id' => $this->subject->id], '127.0.0.1');
        (new AuthService($this->pdo, $this->config))->changePassword($this->subject,
            'a very secure password', 'replacement password that stays private', '127.0.0.2');
        $admin = new AdminService($this->pdo);
        $entries = $admin->auditEntries($this->root, userId: $this->subject->id);
        $actions = array_column($entries, 'action');
        self::assertContains('test.actor', $actions);
        self::assertContains('test.target', $actions);
        self::assertSame(1, count(array_filter($actions, static fn (string $a): bool => $a === 'test.both')));
        self::assertNotContains('test.room', $actions);
        self::assertNotContains('test.metadata', $actions);
        $json = json_encode($entries, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('a very secure password', $json);
        self::assertStringNotContainsString('replacement password that stays private', $json);
        $first = $admin->auditEntries($this->root, limit: 1, userId: $this->subject->id);
        $second = $admin->auditEntries($this->root, beforeId: $first[0]['id'], userId: $this->subject->id);
        self::assertNotContains($first[0]['id'], array_column($second, 'id'));
        self::assertSame([], $admin->auditEntries($this->root, userId: 999999));
        $this->reject(400, fn () => $admin->auditEntries($this->root, userId: 0));
        $this->pdo->exec('DELETE FROM users WHERE id = ' . $this->subject->id);
        $retained = array_column($admin->auditEntries($this->root, userId: $this->subject->id), 'action');
        self::assertContains('test.target', $retained, 'Retained subject IDs do not depend on a live account.');
        self::assertContains('test.both', $retained);
        self::assertNotContains('test.actor', $retained, 'A deleted actor FK is null, not an inferred metadata match.');
    }

    public function testSubjectCaseRowsAndCountsKeepMessagePersonAndRoomScopeWithNoEvidenceInLists(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $mod = $auth->register('RoomMod', 'a very secure password', '127.0.0.4');
        $rooms = new RoomService($this->pdo);
        $a = $rooms->create($this->root, 'room-a', 'Room A', '', 'public', 0, 0, '127.0.0.1');
        $b = $rooms->create($this->root, 'room-b', 'Room B', '', 'public', 0, 0, '127.0.0.1');
        foreach ([$a, $b] as $room) {
            $rooms->join($this->subject, $room->id, '127.0.0.2');
            $rooms->join($this->reporter, $room->id, '127.0.0.3');
        }
        $rooms->join($mod, $a->id, '127.0.0.4');
        $rooms->setRole($this->root, $a->id, $mod->id, 'moderator', '127.0.0.1');
        $messages = new MessageService($this->pdo);
        $reports = new ReportService($this->pdo);
        $messageA = $reports->reportRoomMessage($this->reporter,
            $messages->send($this->subject, $a->id, 'private evidence A')['id'], 'spam', 'private reporter detail', '127.0.0.3');
        $messageB = $reports->reportRoomMessage($this->reporter,
            $messages->send($this->subject, $b->id, 'private evidence B')['id'], 'spam', null, '127.0.0.3');
        $dm = (new DirectMessageService($this->pdo))->send($this->subject, $this->reporter->id, 'private DM evidence');
        $reports->reportDirectMessage($this->reporter, $dm['id'], 'spam', null, '127.0.0.3');
        $people = new UserReportService($this->pdo, $this->config);
        $personA = $people->report($this->reporter, $this->subject->id, 'spam', 'private person detail', $a->id, '127.0.0.3');
        $people->report($this->reporter, $this->subject->id, 'spam', null, $b->id, '127.0.0.3');
        $people->report($this->reporter, $this->subject->id, 'spam', null, null, '127.0.0.3');
        $reports->reportRoomMessage($this->reporter, $messages->send($this->root, $a->id, 'other author')['id'], 'spam', null, '127.0.0.3');
        $reports->resolve($this->root, $messageA['id'], 'resolved', 'user_warned', null, '127.0.0.1');
        $reports->resolve($this->root, $personA['id'], 'dismissed', 'no_violation', null, '127.0.0.1');
        $reports->claim($this->root, $messageB['id'], true, '127.0.0.1');
        $root = $reports->cases($this->root, status: 'all', subjectUserId: $this->subject->id, limit: 2);
        self::assertSame(['open' => 3, 'in_review' => 1, 'resolved' => 1, 'dismissed' => 1], $root['counts']);
        self::assertTrue($root['has_more']);
        $next = $reports->cases($this->root, status: 'all', beforeId: $root['next_before_id'], subjectUserId: $this->subject->id);
        self::assertCount(4, $next['cases']);
        foreach ([...$root['cases'], ...$next['cases']] as $case) self::assertSame($this->subject->id, $case['subject']['id']);
        $local = $reports->cases($mod, subjectUserId: $this->subject->id);
        self::assertSame([], $local['cases'], 'Default open list is empty but scoped status totals remain.');
        self::assertSame(['open' => 0, 'in_review' => 0, 'resolved' => 1, 'dismissed' => 1], $local['counts']);
        $allLocal = $reports->cases($mod, status: 'all', subjectUserId: $this->subject->id);
        self::assertCount(2, $allLocal['cases']);
        foreach ($allLocal['cases'] as $case) self::assertSame($a->id, $case['room']['id']);
        $json = json_encode($allLocal, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private', $json);
        self::assertStringNotContainsString('evidence_body', $json);
        $personOnly = $reports->cases($mod, status: 'all', subjectKind: 'user', subjectUserId: $this->subject->id);
        self::assertCount(1, $personOnly['cases']);
        self::assertSame(1, array_sum($personOnly['counts']));
        self::assertSame(0, array_sum($reports->cases($this->root, subjectUserId: 999999)['counts']));
        self::assertArrayNotHasKey('counts', $reports->cases($this->root));
        $this->reject(400, fn () => $reports->cases($this->root, subjectUserId: 0));
        $this->reject(403, fn () => $reports->cases($this->reporter, subjectUserId: $this->subject->id));
    }

    private function reject(int $status, Closure $call): void
    {
        try {
            $call();
            self::fail('Expected request rejection.');
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status);
        }
    }
}
