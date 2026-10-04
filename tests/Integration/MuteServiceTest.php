<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationOptions;
use ChitChat\Moderation\MuteService;
use ChitChat\Reactions\RoomReactionService;
use ChitChat\Realtime\TypingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use DateTimeImmutable;

final class MuteServiceTest extends DatabaseTestCase
{
    public function testARoomMuteStopsPostingThereOnlyAndCanTellTheRoom(): void
    {
        [$root, $owner, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $lounge = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.2');
        $other = $rooms->create($owner, 'other', 'Other', '', 'public', 0, 0, '127.0.0.2');
        $rooms->join($member, $lounge->id, '127.0.0.3');
        $rooms->join($member, $other->id, '127.0.0.3');
        $messages = new MessageService($this->pdo);
        $before = $messages->send($member, $lounge->id, 'Before the mute');
        $mutes = new MuteService($this->pdo);

        $mute = $mutes->mute($owner, $member->id, $lounge->id, new DateTimeImmutable('+1 hour'), 'Cool down', true, '127.0.0.2');
        self::assertSame('room', $mute['scope']);
        self::assertSame('Cool down', $mute['reason']);

        $this->assertMuted(fn () => $messages->send($member, $lounge->id, 'Still here?'));
        $this->assertMuted(fn () => (new RoomReactionService($this->pdo))->add($member, $before['id'], "\u{1F44D}"));
        self::assertFalse((new TypingService($this->pdo))->inRoom($member, $lounge->id), 'A muted person shows nobody that they type.');
        $messages->send($member, $other->id, 'Other rooms are fine');
        (new DirectMessageService($this->pdo))->send($member, $owner->id, 'And direct messages too');

        // The room was told, without reason or moderator, and the person was notified.
        $notice = $this->pdo->query("SELECT body FROM room_messages WHERE room_id = {$lounge->id} AND message_type = 'system'")?->fetchColumn();
        self::assertSame('Member was muted in this room for 1 hour.', $notice);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'muted'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'moderation.mute'"));

        // The room list says so, for the composer.
        $listed = array_column((new RoomService($this->pdo))->list($member), 'muted', 'id');
        self::assertSame($mute['id'], $listed[$lounge->id]['id'] ?? null);
        self::assertNull($listed[$other->id]);

        $mutes->lift($root, $mute['id'], '127.0.0.1');
        $messages->send($member, $lounge->id, 'Back again');
    }

    public function testMutingEverywhereNeedsGlobalStaffAndStopsDirectMessages(): void
    {
        [$root, $owner, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $lounge = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.2');
        $rooms->join($member, $lounge->id, '127.0.0.3');
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $owner = $this->reload($owner);
        $mutes = new MuteService($this->pdo);

        // A room owner cannot mute everywhere, and a member cannot mute anyone.
        $this->assertForbidden(fn () => $mutes->mute($owner, $member->id, null, null, '', false, '127.0.0.2'));
        $this->assertForbidden(fn () => $mutes->mute($member, $owner->id, $lounge->id, null, '', false, '127.0.0.3'));

        $mute = $mutes->mute($root, $member->id, null, null, 'Spam', false, '127.0.0.1');
        self::assertSame('everywhere', $mute['scope']);
        self::assertNull($mute['expires_at'], 'Until lifted.');
        $this->assertMuted(fn () => (new MessageService($this->pdo))->send($member, $lounge->id, 'Hello?'));
        $this->assertMuted(fn () => (new DirectMessageService($this->pdo))->send($member, $owner->id, 'Hello?'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM room_messages WHERE message_type = 'system'"), 'Nobody was told.');

        // A mute that ran out no longer counts.
        $this->pdo->exec("UPDATE user_mutes SET created_at = NOW() - INTERVAL '2 hours', expires_at = NOW() - INTERVAL '1 hour'");
        (new MessageService($this->pdo))->send($member, $lounge->id, 'It ran out');
    }

    public function testTheProfileCardOffersOnlyWhatTheViewerMayDo(): void
    {
        [$root, $owner, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $lounge = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.2');
        $rooms->join($member, $lounge->id, '127.0.0.3');
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $owner = $this->reload($owner);
        $options = new ModerationOptions($this->pdo);

        $forOwner = $options->for($owner, $member->id, $lounge->id);
        self::assertNotNull($forOwner);
        self::assertTrue($forOwner['room']['can_mute'] ?? false);
        self::assertTrue($forOwner['room']['can_remove'] ?? false);
        self::assertNull($forOwner['everywhere'], 'A room owner has nothing to offer everywhere.');

        $forRoot = $options->for($root, $member->id, $lounge->id);
        self::assertTrue($forRoot['everywhere']['can_ban'] ?? false);
        self::assertTrue($forRoot['everywhere']['can_mute'] ?? false);

        self::assertNull($options->for($member, $owner->id, $lounge->id), 'A member moderates nobody.');
        self::assertNull($options->for($root, $root->id, $lounge->id), 'Nobody moderates themselves.');
    }

    /** @return array{AuthenticatedUser, AuthenticatedUser, AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $owner = $auth->register('Owner', 'another secure password', '127.0.0.2');
        $member = $auth->register('Member', 'one more secure password', '127.0.0.3');
        // Room owners here need to be able to create rooms.
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin')");

        return [$root, $this->reload($owner), $member];
    }

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $reloaded = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function assertMuted(callable $action): void
    {
        try {
            $action();
            self::fail('Expected the muted person to be stopped.');
        } catch (ApiException $exception) {
            self::assertSame('muted', $exception->errorCode);
        }
    }

    private function assertForbidden(callable $action): void
    {
        try {
            $action();
            self::fail('Expected the action to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
    }

    private function countRows(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }
}
