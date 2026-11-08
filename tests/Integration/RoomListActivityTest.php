<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\IgnoreService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Http\ApiException;
use ChitChat\Presence\PresenceService;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use DateTimeImmutable;
use PDO;
use PDOStatement;

final class RoomListActivityTest extends DatabaseTestCase
{
    public function testCountsDistinctPeopleAndGuestsWithThePresenceListsExpiryRules(): void
    {
        [$root, $viewer, $peer] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'lobby', 'Lobby', '', 'public', 0, 120, '127.0.0.1', 'write');
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        foreach ([$viewer, $peer, $guest] as $person) {
            $rooms->join($person, $room->id, '127.0.0.1');
        }
        $presence = new PresenceService($this->pdo, $this->config);
        foreach ([$viewer, $peer, $peer, $guest, $root] as $index => $person) {
            $connection = sprintf('00000000-0000-4000-8000-%012d', $index + 1);
            $presence->heartbeat($person, $connection, $room->id, true);
        }
        $this->pdo->exec("UPDATE room_presence SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE user_id = {$root->id}");
        self::assertSame(2, $this->listed($viewer, $room->id)['active_count']);
        self::assertSame(2, $this->listed($guest, $room->id)['active_count']);
        $visibleOthers = array_filter($presence->list($viewer, $room->id), static fn (array $person): bool => $person['id'] !== $viewer->id);
        self::assertCount($this->listed($viewer, $room->id)['active_count'], $visibleOthers);
        self::assertNull($this->listed($viewer, $room->id)['last_activity_at']);

        $this->pdo->exec("UPDATE room_presence SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE connection_id = '00000000-0000-4000-8000-000000000002'");
        $this->pdo->exec("UPDATE room_presence SET last_interaction_at = NOW() - INTERVAL '121 seconds' WHERE user_id = {$peer->id}");
        self::assertSame(1, $this->listed($viewer, $room->id)['active_count']);
        $this->pdo->exec("UPDATE room_presence SET lease_expires_at = NOW() - INTERVAL '1 second' WHERE user_id = {$guest->id}");
        self::assertSame(0, $this->listed($viewer, $room->id)['active_count']);
        self::assertCount(1, $presence->list($viewer, $room->id)); // Only the viewer remains.
        $this->pdo->exec("UPDATE rooms SET inactivity_timeout_seconds = 0 WHERE id = {$room->id}");
        self::assertSame(1, $this->listed($viewer, $room->id)['active_count']);
    }

    public function testRecencyCountsIgnoredMessagesButExcludesDeletedRetainedOutAndPrivatePings(): void
    {
        [$root, $viewer, $peer] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        foreach ([$viewer, $peer] as $person) {
            $rooms->join($person, $room->id, '127.0.0.1');
        }
        $messages = new MessageService($this->pdo);
        $older = $messages->send($root, $room->id, 'Older visible message');
        $ignored = $messages->send($peer, $room->id, 'Ignored but visible message');
        $deleted = $messages->send($root, $room->id, 'Deleted message');
        $removed = $messages->send($root, $room->id, 'Retention-removed message');
        $this->pdo->exec("UPDATE room_messages SET created_at = '2026-01-01T10:00:00Z' WHERE id = {$older['id']}");
        $this->pdo->exec("UPDATE room_messages SET created_at = '2026-01-02T12:00:00+02:00' WHERE id = {$ignored['id']}");
        $this->pdo->exec("UPDATE room_messages SET created_at = '2026-01-03T10:00:00Z', deleted_at = NOW(), deleted_by = {$root->id} WHERE id = {$deleted['id']}");
        $this->pdo->exec("DELETE FROM room_messages WHERE id = {$removed['id']}");
        (new IgnoreService($this->pdo))->setIgnored($viewer, $peer->id, true);
        (new PingService($this->pdo))->send($root, $room->id, $viewer->username, 'A later private ping');
        self::assertSame('2026-01-02T10:00:00+00:00', $this->listed($viewer, $room->id)['last_activity_at']);
        $this->pdo->exec("UPDATE room_messages SET deleted_at = NOW(), deleted_by = {$root->id} WHERE id = {$ignored['id']}");
        self::assertSame('2026-01-01T10:00:00+00:00', $this->listed($viewer, $room->id)['last_activity_at']);
        $this->pdo->exec("DELETE FROM room_messages WHERE id = {$older['id']}");
        self::assertNull($this->listed($viewer, $room->id)['last_activity_at']);
    }

    public function testOutsidersInvitationsAndModeratorsWithoutMembershipGetUnknownActivity(): void
    {
        [$root, $viewer, $peer] = $this->people();
        $rooms = new RoomService($this->pdo);
        $public = $rooms->create($root, 'public', 'Public', '', 'public', 0, 0, '127.0.0.1');
        $private = $rooms->create($root, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $unlisted = $rooms->create($root, 'unlisted', 'Unlisted', '', 'unlisted', 0, 0, '127.0.0.1');
        $rooms->invite($root, $private->id, $viewer->id, '127.0.0.1');
        (new MessageService($this->pdo))->send($root, $private->id, 'Private history');
        foreach ([$public, $private] as $room) {
            $listed = $this->listed($viewer, $room->id);
            self::assertNull($listed['active_count']);
            self::assertNull($listed['last_activity_at']);
        }
        self::assertNotContains($unlisted->id, array_column($rooms->list($viewer), 'id'));
        $creator = new AuthenticatedUser($peer->id, $peer->username, ['chat_admin'], $peer->sessionVersion);
        $other = $rooms->create($creator, 'other', 'Other', '', 'private', 0, 0, '127.0.0.3');
        self::assertNull($this->listed($root, $other->id)['active_count']);
        self::assertNull($this->listed($root, $other->id)['last_activity_at']);
        $rooms->join($viewer, $public->id, '127.0.0.2');
        self::assertSame(0, $this->listed($viewer, $public->id)['active_count']);
    }

    public function testHistoryAgeChangesHideBothValuesAndKeepTheModeratorBypass(): void
    {
        [$root, $viewer, $peer] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'age', 'Age', '', 'public', 0, 0, '127.0.0.1');
        foreach ([$viewer, $peer] as $person) {
            $rooms->join($person, $room->id, '127.0.0.1');
        }
        (new MessageService($this->pdo))->send($root, $room->id, 'Age-restricted history');
        $minorDate = (new DateTimeImmutable('today'))->modify('-15 years')->format('Y-m-d');
        $this->pdo->prepare('UPDATE users SET birth_date = :birth WHERE id = :id')->execute(['birth' => $minorDate, 'id' => $viewer->id]);
        $rooms->update($root, $room->id, 'Age', '', 'public', 18, 0, '127.0.0.1');
        foreach ([$viewer, $peer] as $person) {
            self::assertNull($this->listed($person, $room->id)['active_count']);
            self::assertNull($this->listed($person, $room->id)['last_activity_at']);
        }
        try {
            (new MessageService($this->pdo))->history($viewer, $room->id);
            self::fail('Expected the same minimum-age restriction as history.');
        } catch (ApiException $exception) {
            self::assertSame('minimum_age_not_met', $exception->errorCode);
        }
        self::assertSame(0, $this->listed($root, $room->id)['active_count']);
        self::assertNotNull($this->listed($root, $room->id)['last_activity_at']);
        $adultDate = (new DateTimeImmutable('today'))->modify('-25 years')->format('Y-m-d');
        $this->pdo->prepare('UPDATE users SET birth_date = :birth WHERE id = :id')->execute(['birth' => $adultDate, 'id' => $viewer->id]);
        self::assertSame(0, $this->listed($viewer, $room->id)['active_count']);
        self::assertNotNull($this->listed($viewer, $room->id)['last_activity_at']);
    }

    public function testListingManyJoinedRoomsExecutesAConstantNumberOfQueries(): void
    {
        [$root, $viewer] = $this->people();
        $rooms = new RoomService($this->pdo);
        for ($index = 0; $index < 12; $index++) {
            $room = $rooms->create($root, 'room-' . $index, 'Room ' . $index, '', 'public', 0, 0, '127.0.0.1');
            $rooms->join($viewer, $room->id, '127.0.0.2');
        }
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RoomActivityCountingStatement::class]);
        RoomActivityCountingStatement::$executionCount = 0;
        try {
            $listed = $rooms->list($viewer);
            self::assertCount(12, $listed);
            self::assertSame(4, RoomActivityCountingStatement::$executionCount);
            self::assertSame(array_fill(0, 12, 0), array_column($listed, 'active_count'));
        } finally {
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
        }
    }

    /** @return array{AuthenticatedUser, AuthenticatedUser, AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        return [
            $auth->register('Root', 'a very secure password', '127.0.0.1'),
            $auth->register('Viewer', 'another secure password', '127.0.0.2'),
            $auth->register('Peer', 'different secure password', '127.0.0.3'),
        ];
    }

    /** @return array<string, mixed> */
    private function listed(AuthenticatedUser $actor, int $roomId): array
    {
        foreach ((new RoomService($this->pdo))->list($actor) as $room) {
            if ($room['id'] === $roomId) {
                return $room;
            }
        }
        self::fail('Expected the room to be listed.');
    }
}

/** Counts database round trips, rather than asserting the query's source text. */
final class RoomActivityCountingStatement extends PDOStatement
{
    public static int $executionCount = 0;

    public function execute(?array $params = null): bool
    {
        self::$executionCount++;
        return parent::execute($params);
    }
}
