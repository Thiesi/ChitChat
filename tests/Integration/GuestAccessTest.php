<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\SystemSettingsService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationOptions;
use ChitChat\Presence\PresenceService;
use ChitChat\Reactions\RoomReactionService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomRepository;
use ChitChat\Room\RoomService;

final class GuestAccessTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testGuestsCanOnlyStartWhileGuestAccessIsOn(): void
    {
        $guests = new GuestService($this->pdo);
        self::assertFalse($guests->available());
        $this->assertRefused('guest_access_disabled', fn () => $guests->start('10.0.0.1'));

        $this->enableGuests();
        self::assertTrue($guests->available());
        $guest = $guests->start('10.0.0.1');

        self::assertTrue($guest->guest);
        self::assertSame([], $guest->roles);
        self::assertMatchesRegularExpression('/\AGuest \d{4,}\z/', $guest->username);
        self::assertNotNull($guest->guestExpiresAt);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$guest->id} AND kind = 'guest_welcome'"));
        self::assertTrue($guest->toSessionArray()['guest']);

        // Numbers are never reused.
        $next = $guests->start('10.0.0.2');
        self::assertNotSame($guest->username, $next->username);

        // During a lockdown no guest gets in.
        $this->pdo->exec('UPDATE system_settings SET lockdown_enabled = TRUE WHERE id = 1');
        self::assertFalse($guests->available());
        $this->assertRefused('guest_access_paused', fn () => $guests->start('10.0.0.3'));
    }

    public function testEndpointsAreClosedToGuestsUnlessTheyOptIn(): void
    {
        $this->enableGuests();
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $users = new UserRepository($this->pdo);
        $_SESSION['auth'] = ['user_id' => $guest->id, 'session_version' => $guest->sessionVersion, 'authenticated_at' => time()];

        self::assertSame($guest->id, SessionManager::requireUserOrGuest($users)->id);
        $this->assertRefused('guest_not_allowed', fn () => SessionManager::requireUser($users));
    }

    public function testAGuestSessionEndsWhenIdleExpiredOrSwitchedOff(): void
    {
        $this->enableGuests();
        $guests = new GuestService($this->pdo);
        $idle = $guests->start('10.0.0.1');
        $expired = $guests->start('10.0.0.2');
        $active = $guests->start('10.0.0.3');
        self::assertTrue($guests->touch($idle->id));

        $this->pdo->exec("UPDATE users SET guest_last_seen_at = NOW() - INTERVAL '3 hours' WHERE id = {$idle->id}");
        $this->pdo->exec("UPDATE users SET guest_expires_at = NOW() - INTERVAL '1 minute' WHERE id = {$expired->id}");
        self::assertFalse($guests->touch($idle->id));
        self::assertFalse($guests->touch($expired->id));
        self::assertTrue($guests->touch($active->id));

        self::assertSame(2, $guests->expiredCount());
        self::assertSame(2, $guests->endExpired());
        // An ended guest keeps its name, so its messages stay readable.
        self::assertSame($idle->username, $this->pdo->query("SELECT username FROM users WHERE id = {$idle->id} AND account_state = 'closed'")?->fetchColumn());
        self::assertNull((new UserRepository($this->pdo))->findAuthenticatedById($idle->id));

        // Switching guest access off ends the rest.
        [$root] = $this->people();
        (new SystemSettingsService($this->pdo))->update($root, true, false, 0, 0, 0, 30, 24, 168, 30, '127.0.0.1', null, false);
        self::assertFalse($guests->touch($active->id));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE account_kind = 'guest' AND account_state = 'active'"));
    }

    public function testGuestsSeeOnlyRoomsThatLetGuestsInAndWriteOnlyWhereAllowed(): void
    {
        [$root, $owner, $member] = $this->people();
        $this->enableGuests();
        $rooms = new RoomService($this->pdo);
        $closed = $rooms->create($owner, 'closed', 'Closed', '', 'public', 0, 0, '127.0.0.2');
        $readable = $rooms->create($owner, 'news', 'News', '', 'public', 0, 0, '127.0.0.2', 'read');
        $open = $rooms->create($owner, 'lobby', 'Lobby', '', 'public', 0, 0, '127.0.0.2', 'write');
        $rooms->join($member, $open->id, '127.0.0.3');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');

        self::assertSame(['Lobby', 'News'], array_column($rooms->list($guest), 'name'));
        self::assertSame('write', $rooms->list($guest)[0]['guest_access']);
        self::assertNull((new RoomRepository($this->pdo))->findForUser($closed->id, $guest->id));
        $this->assertRefused('room_not_found', fn () => $rooms->join($guest, $closed->id, '10.0.0.1'));

        $messages = new MessageService($this->pdo);
        $rooms->join($guest, $readable->id, '10.0.0.1');
        $this->assertRefused('guest_read_only', fn () => $messages->send($guest, $readable->id, 'Hello?'));
        $news = $messages->send($owner, $readable->id, 'Release tonight');
        $this->assertRefused('guest_read_only', fn () => (new RoomReactionService($this->pdo))->add($guest, $news['id'], "\u{1F44D}"));
        self::assertCount(1, $messages->history($guest, $readable->id));

        // Where guests may write, a guest's @names notify nobody, and nobody mentions a guest.
        $rooms->join($guest, $open->id, '10.0.0.1');
        $messages->send($guest, $open->id, 'Hi @Member and @room!');
        $messages->send($member, $open->id, '@room and @' . str_replace(' ', '', $guest->username) . ' welcome');
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE kind = 'mentioned' AND user_id IN ({$member->id}, {$guest->id})"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM account_notifications WHERE kind = 'mentioned' AND user_id = {$owner->id}"), 'Only members hear @room.');

        // Guests do not ping and are not pinged.
        $pings = new PingService($this->pdo);
        $this->assertRefused('guest_not_allowed', fn () => $pings->send($guest, $open->id, 'Member'));
        // A guest's name has a space, which no username can have, so it cannot be addressed.
        $this->assertRefused('invalid_username', fn () => $pings->send($member, $open->id, $guest->username));
        self::assertNotNull($root);
    }

    public function testClosingARoomToGuestsRemovesThemAndStopsItsEvents(): void
    {
        [, $owner] = $this->people();
        $this->enableGuests();
        $rooms = new RoomService($this->pdo);
        $lobby = $rooms->create($owner, 'lobby', 'Lobby', '', 'public', 0, 0, '127.0.0.2', 'write');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $rooms->join($guest, $lobby->id, '10.0.0.1');
        $events = new EventRepository($this->pdo);
        $before = $this->latestEventId();

        // A room that is no longer public cannot keep its guests.
        $this->assertRefused('guest_access_scope', fn () => $rooms->update($owner, $lobby->id, 'Lobby', '', 'private', 0, 0, '127.0.0.2', 'read'));
        $updated = $rooms->update($owner, $lobby->id, 'Lobby', '', 'private', 0, 0, '127.0.0.2');
        self::assertSame('none', $updated->guestAccess);
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM room_members WHERE user_id = {$guest->id}"));

        (new MessageService($this->pdo))->send($owner, $lobby->id, 'Members only now');
        $seen = array_filter($events->visibleAfter($guest, $before), static fn ($event): bool => $event->type === 'room_message');
        self::assertSame([], array_values($seen));
    }

    public function testGuestsTakeNoPartInDirectMessagesOrRoles(): void
    {
        [$root, , $member] = $this->people();
        $this->enableGuests();
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $directMessages = new DirectMessageService($this->pdo);

        $this->assertRefused('user_not_found', fn () => $directMessages->send($member, $guest->id, 'Hello guest'));
        self::assertSame([], $directMessages->searchUsers($member, 'guest'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM user_roles WHERE user_id = {$guest->id}"));
        self::assertNotNull($root);
    }

    public function testModeratorsCanEndAGuestOrBlockItsConnection(): void
    {
        [$root, , $member] = $this->people();
        $this->enableGuests();
        $guests = new GuestService($this->pdo);
        $first = $guests->start('10.0.0.1');
        $second = $guests->start('10.0.0.1');
        $third = $guests->start('10.0.0.1');
        // At most three guests from one connection at a time.
        $this->assertRefused('guest_limit_reached', fn () => $guests->start('10.0.0.1'));

        // The profile card offers guest actions to global staff only.
        $options = (new ModerationOptions($this->pdo))->for($root, $first->id, null);
        self::assertSame(['can_end' => true, 'can_block_connection' => true], $options['guest'] ?? null);
        self::assertFalse($options['everywhere']['can_ban'] ?? true, 'A guest is not banned but ended.');
        self::assertNull((new ModerationOptions($this->pdo))->for($member, $first->id, null));
        $this->assertRefused('forbidden', fn () => $guests->endByModerator($member, $first->id, '127.0.0.3'));

        $guests->endByModerator($root, $first->id, '127.0.0.1');
        self::assertNull((new UserRepository($this->pdo))->findAuthenticatedById($first->id));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM realtime_events WHERE event_type = 'forced_logout' AND target_user_id = {$first->id}"));

        $this->assertRefused('validation_error', fn () => $guests->blockConnection($root, $second->id, 60, '', '127.0.0.1'));
        $guests->blockConnection($root, $second->id, 3_600, 'Spam', '127.0.0.1');
        self::assertNull((new UserRepository($this->pdo))->findAuthenticatedById($third->id), 'Every guest from the connection ends.');
        $this->assertRefused('guest_access_blocked', fn () => $guests->start('10.0.0.1'));
        $guests->start('10.0.0.2');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'guest.connection_blocked'"));
    }

    public function testPresenceMarksGuests(): void
    {
        [, $owner] = $this->people();
        $this->enableGuests();
        $rooms = new RoomService($this->pdo);
        $lobby = $rooms->create($owner, 'lobby', 'Lobby', '', 'public', 0, 0, '127.0.0.2', 'read');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $rooms->join($guest, $lobby->id, '10.0.0.1');
        $presence = new PresenceService($this->pdo, $this->config);
        $presence->heartbeat($guest, '0b7f6c1e-2a7d-4c1e-9a3b-1d2e3f4a5b6c', $lobby->id, true);
        $presence->heartbeat($owner, '1b7f6c1e-2a7d-4c1e-9a3b-1d2e3f4a5b6c', $lobby->id, true);

        $listed = array_column($presence->list($owner, $lobby->id), 'guest', 'username');
        self::assertSame([$guest->username => true, 'Owner' => false], $listed);
    }

    private function enableGuests(): void
    {
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
    }

    /** @return array{0:AuthenticatedUser, 1:AuthenticatedUser, 2:AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $owner = $auth->register('Owner', 'another secure password', '127.0.0.2');
        $member = $auth->register('Member', 'one more secure password', '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin')");
        $users = new UserRepository($this->pdo);
        $root = $users->findAuthenticatedById($root->id);
        $owner = $users->findAuthenticatedById($owner->id);
        self::assertNotNull($root);
        self::assertNotNull($owner);

        return [$root, $owner, $member];
    }

    private function assertRefused(string $code, callable $action): void
    {
        try {
            $action();
            self::fail('Expected the action to be refused with ' . $code . '.');
        } catch (ApiException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    private function latestEventId(): int
    {
        return $this->countRows('SELECT COALESCE(MAX(id), 0) FROM realtime_events');
    }

    private function countRows(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }
}
