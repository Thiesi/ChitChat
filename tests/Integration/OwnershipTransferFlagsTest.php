<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\RoomAdminService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationOptions;
use ChitChat\Room\ModerationRank;
use ChitChat\Room\RoomService;

/**
 * The ownership flags on the profile card and the administration snapshot (#302) say what the
 * transfer would do (#273): each case checks the flags, then the transfer itself.
 */
final class OwnershipTransferFlagsTest extends DatabaseTestCase
{
    private const PASSWORD = 'Ownership-Flags-Test-Aa1!';

    private AuthService $auth;
    private RoomService $rooms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->pdo, $this->config);
        $this->rooms = new RoomService($this->pdo);
    }

    public function testTheOwnerMayHandTheRoomToAMember(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('NewOwner', $roomId);

        self::assertTrue($this->profileFlag($owner, $roomId, $member->id));
        self::assertSame([$owner->id => false, $member->id => true], $this->snapshotFlags($owner, $roomId), "The owner's own row is false.");
        self::assertFalse($this->snapshot($owner, $roomId)['room']['can_take_ownership'], 'Already the owner.');
        self::assertSame(200, $this->outcome($owner, $roomId, $member->id));
    }

    public function testNonMembersAndTheOwnerThemselvesAreNotOffered(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $outsider = $this->auth->register('Outsider', self::PASSWORD, '127.0.0.5');

        self::assertFalse($this->profileFlag($owner, $roomId, $outsider->id));
        self::assertSame(404, $this->outcome($owner, $roomId, $outsider->id));
        self::assertSame(409, $this->outcome($owner, $roomId, $owner->id));
    }

    public function testModeratorsAndGlobalModeratorsAreNotOffered(): void
    {
        [, $roomId] = $this->ownedRoom();
        $member = $this->member('Member', $roomId);
        $moderator = $this->member('RoomModerator', $roomId);
        $this->pdo->exec("UPDATE room_members SET role = 'moderator' WHERE room_id = {$roomId} AND user_id = {$moderator->id}");
        $moderator = $this->reload($moderator);
        $global = $this->staff('GlobalModerator', 'global_moderator');

        foreach ([$moderator, $global] as $viewer) {
            self::assertFalse($this->profileFlag($viewer, $roomId, $member->id), $viewer->username);
            self::assertSame(403, $this->outcome($viewer, $roomId, $member->id), $viewer->username);
        }
    }

    public function testGuestsAndClosingAccountsAreNotOfferedToAnyone(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $this->pdo->exec("UPDATE rooms SET guest_access = 'write' WHERE id = {$roomId}");
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $this->rooms->join($guest, $roomId, '10.0.0.1');
        $closing = $this->member('Closing', $roomId);
        $this->pdo->exec("UPDATE users SET account_state = 'closure_pending', closure_requested_at = NOW(), closure_finalizes_at = NOW() + INTERVAL '14 days' WHERE id = {$closing->id}");
        $admin = $this->staff('Admin', 'admin');

        foreach ([$guest->id, $closing->id] as $target) {
            foreach ([$owner, $admin] as $viewer) {
                self::assertFalse($this->profileFlag($viewer, $roomId, $target));
                self::assertFalse($this->snapshotFlags($viewer, $roomId)[$target]);
                self::assertSame(409, $this->outcome($viewer, $roomId, $target));
            }
        }
    }

    public function testOnlyViewersWhoCanSeeBansAreToldABannedMemberCantOwnTheRoom(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $banned = $this->member('Banned', $roomId);
        $admin = $this->staff('Admin', 'admin');
        $chatAdmin = $this->staff('ChatAdmin', 'chat_admin');
        $this->pdo->exec("INSERT INTO user_bans (user_id, created_by, reason) VALUES ({$banned->id}, {$admin->id}, 'Test ban')");

        // Administrators see bans in the user directory, so their flag is exact.
        self::assertFalse($this->profileFlag($admin, $roomId, $banned->id));
        self::assertFalse($this->snapshotFlags($admin, $roomId)[$banned->id]);
        // The owner and a Chat Admin don't see bans: the flag must not tell them. The transfer still refuses.
        foreach ([$owner, $chatAdmin] as $viewer) {
            self::assertTrue($this->profileFlag($viewer, $roomId, $banned->id), $viewer->username);
            self::assertTrue($this->snapshotFlags($viewer, $roomId)[$banned->id], $viewer->username);
            self::assertSame(409, $this->outcome($viewer, $roomId, $banned->id), $viewer->username);
        }
        self::assertSame(409, $this->outcome($admin, $roomId, $banned->id));
    }

    public function testAnActiveOwnerWithAHigherRankIsOfferedOnlyToEqualRanks(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'admin')");
        $member = $this->member('NewOwner', $roomId);
        $chatAdmin = $this->staff('ChatAdmin', 'chat_admin');
        $admin = $this->staff('OtherAdmin', 'admin');

        self::assertFalse($this->profileFlag($chatAdmin, $roomId, $member->id));
        self::assertFalse($this->snapshot($chatAdmin, $roomId)['room']['can_take_ownership']);
        self::assertSame(403, $this->outcome($chatAdmin, $roomId, $member->id));
        self::assertTrue($this->profileFlag($admin, $roomId, $member->id));
        self::assertSame(200, $this->outcome($admin, $roomId, $member->id));
    }

    public function testAClosedOwnersRoomIsOfferedToAnyManager(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'admin')");
        $this->pdo->exec("UPDATE users SET account_state = 'closed', closure_requested_at = NOW() - INTERVAL '15 days',
            closure_finalizes_at = NOW() - INTERVAL '1 day', closed_at = NOW(), username = 'Closed account #{$owner->id}' WHERE id = {$owner->id}");
        $member = $this->member('NewOwner', $roomId);
        $chatAdmin = $this->staff('ChatAdmin', 'chat_admin');

        self::assertTrue($this->profileFlag($chatAdmin, $roomId, $member->id));
        self::assertTrue($this->snapshot($chatAdmin, $roomId)['room']['can_take_ownership']);
        self::assertSame(200, $this->outcome($chatAdmin, $roomId, $member->id));
    }

    public function testATakeoverIsOfferedOnlyWhenTheRoomsMinimumAgeIsMet(): void
    {
        [, $roomId] = $this->ownedRoom('public', 18);
        $admin = $this->staff('Admin', 'admin');
        $offered = fn (): bool => $this->snapshot($admin, $roomId)['room']['can_take_ownership'];

        self::assertFalse($offered(), 'No birth date.');
        self::assertSame(403, $this->outcome($admin, $roomId, $admin->id));
        $this->pdo->exec("UPDATE users SET birth_date = CURRENT_DATE - INTERVAL '17 years' WHERE id = {$admin->id}");
        self::assertFalse($offered(), 'Too young.');
        self::assertSame(403, $this->outcome($admin, $roomId, $admin->id));
        $this->pdo->exec("UPDATE users SET birth_date = CURRENT_DATE - INTERVAL '30 years' WHERE id = {$admin->id}");
        self::assertTrue($offered());
        self::assertArrayNotHasKey($admin->id, $this->snapshotFlags($admin, $roomId), 'Not a member, so no row.');
        self::assertSame(200, $this->outcome($admin, $roomId, $admin->id));
    }

    public function testTheDatabaseRanksEveryRoleAsThePhpSideDoes(): void
    {
        $user = $this->auth->register('Ranked', self::PASSWORD, '127.0.0.6');
        $case = ModerationRank::globalRankCase('role');
        foreach (ModerationRank::GLOBAL_RANKS as $role => $rank) {
            $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$user->id}");
            $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$user->id}, '{$role}')");
            $sql = "SELECT COALESCE(MAX({$case}), 0) FROM user_roles WHERE user_id = {$user->id}";
            self::assertSame($rank, (int) $this->pdo->query($sql)?->fetchColumn(), $role);
            self::assertSame($rank, ModerationRank::globalRankOf($this->reload($user)), $role);
        }
    }

    private function profileFlag(AuthenticatedUser $viewer, int $roomId, int $targetId): bool
    {
        $options = (new ModerationOptions($this->pdo))->for($viewer, $targetId, $roomId);
        $room = $options['room'] ?? null;

        return is_array($room) && ($room['can_transfer_ownership'] ?? null) === true;
    }

    /** @return array{room: array<string, mixed>, members: list<array<string, mixed>>} */
    private function snapshot(AuthenticatedUser $viewer, int $roomId): array
    {
        return (new RoomAdminService($this->pdo))->snapshot($viewer, $roomId);
    }

    /** @return array<int, bool> */
    private function snapshotFlags(AuthenticatedUser $viewer, int $roomId): array
    {
        $flags = [];
        foreach ($this->snapshot($viewer, $roomId)['members'] as $member) {
            $flags[(int) $member['id']] = $member['can_transfer_ownership'];
        }

        return $flags;
    }

    /** The transfer's HTTP status: 200, or the refusal's. */
    private function outcome(AuthenticatedUser $actor, int $roomId, int $targetId): int
    {
        try {
            $this->rooms->transferOwnership($actor, $roomId, $targetId, '127.0.0.9');

            return 200;
        } catch (ApiException $exception) {
            return $exception->status;
        }
    }

    /** @return array{AuthenticatedUser, int} */
    private function ownedRoom(string $visibility = 'public', int $minimumAge = 0): array
    {
        $this->auth->register('Root', self::PASSWORD, '127.0.0.1');
        $owner = $this->staff('RoomOwner', 'chat_admin');
        $room = $this->rooms->create($owner, 'lounge', 'Lounge', '', $visibility, $minimumAge, 0, '127.0.0.2');
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");

        return [$this->reload($owner), $room->id];
    }

    private function member(string $name, int $roomId): AuthenticatedUser
    {
        $member = $this->auth->register($name, self::PASSWORD, '127.0.0.3');
        $this->pdo->exec("INSERT INTO room_members (room_id, user_id, role) VALUES ({$roomId}, {$member->id}, 'member')");

        return $member;
    }

    private function staff(string $name, string $role): AuthenticatedUser
    {
        $user = $this->auth->register($name, self::PASSWORD, '127.0.0.4');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$user->id}, '{$role}')");

        return $this->reload($user);
    }

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $fresh = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($fresh);

        return $fresh;
    }
}
