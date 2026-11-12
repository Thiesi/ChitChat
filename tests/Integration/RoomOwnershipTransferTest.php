<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomService;
use PDOException;

/** Room ownership transfer (#273). Races are in RoomOwnershipConcurrencyTest. */
final class RoomOwnershipTransferTest extends DatabaseTestCase
{
    private const PASSWORD = 'Room-Ownership-Test-Aa1!';

    private AuthService $auth;
    private RoomService $rooms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->pdo, $this->config);
        $this->rooms = new RoomService($this->pdo);
    }

    public function testTheOwnerHandsTheRoomToAMemberAndStaysAsModerator(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('NewOwner', $roomId);

        $room = $this->rooms->transferOwnership($owner, $roomId, $member->id, '127.0.0.9');

        self::assertSame('moderator', $room->memberRole, 'The returned room is seen by the actor, now a moderator.');
        self::assertSame([$member->id], $this->owners($roomId));
        self::assertSame('moderator', $this->role($roomId, $owner->id));
        self::assertEquals(
            ['from_user_id' => $owner->id, 'to_user_id' => $member->id, 'joined' => false],
            json_decode((string) $this->scalar("SELECT metadata_json::text FROM audit_log WHERE action = 'room.ownership_transferred'"), true),
        );
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'room_ownership_received'"));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE kind = 'room_ownership_removed'"), 'The owner made the change and is not told.');
        // The previous owner, now a moderator, can leave like anyone.
        $this->rooms->leave($this->reload($owner), $roomId, '127.0.0.9');
        self::assertNull($this->role($roomId, $owner->id));
    }

    public function testAnAdministratorTransfersAndBothOwnersAreTold(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('NewOwner', $roomId);
        $admin = $this->staff('ChatAdmin', 'chat_admin');

        $this->rooms->transferOwnership($admin, $roomId, $member->id, '127.0.0.9');

        self::assertSame([$member->id], $this->owners($roomId));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'room_ownership_received'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$owner->id} AND kind = 'room_ownership_removed'"));
    }

    public function testOnlyManagersMayTransfer(): void
    {
        [, $roomId] = $this->ownedRoom();
        $member = $this->member('Member', $roomId);
        $moderator = $this->member('RoomModerator', $roomId);
        $this->pdo->exec("UPDATE room_members SET role = 'moderator' WHERE room_id = {$roomId} AND user_id = {$moderator->id}");
        $global = $this->staff('GlobalModerator', 'global_moderator');

        foreach ([$this->reload($moderator), $member, $global] as $actor) {
            $this->assertRefused(403, 'forbidden', fn () => $this->rooms->transferOwnership($actor, $roomId, $member->id, '127.0.0.9'));
        }
    }

    public function testIneligibleTargetsAreRefused(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $outsider = $this->auth->register('Outsider', self::PASSWORD, '127.0.0.5');
        $this->assertRefused(404, 'membership_not_found', fn () => $this->rooms->transferOwnership($owner, $roomId, $outsider->id, '127.0.0.9'));
        $this->assertRefused(409, 'already_owner', fn () => $this->rooms->transferOwnership($owner, $roomId, $owner->id, '127.0.0.9'));

        $closing = $this->member('Closing', $roomId);
        $this->pdo->exec("UPDATE users SET account_state = 'closure_pending', closure_requested_at = NOW(), closure_finalizes_at = NOW() + INTERVAL '14 days' WHERE id = {$closing->id}");
        $banned = $this->member('Banned', $roomId);
        $this->pdo->exec("INSERT INTO user_bans (user_id, created_by, reason) VALUES ({$banned->id}, {$owner->id}, 'Test ban')");
        foreach ([$closing, $banned] as $target) {
            $this->assertRefused(409, 'transfer_target_not_eligible', fn () => $this->rooms->transferOwnership($owner, $roomId, $target->id, '127.0.0.9'));
        }
        self::assertSame([$owner->id], $this->owners($roomId), 'Nothing changed.');
    }

    public function testAClosedOwnersRoomIsTakenOverAndTheOldOwnerBecomesAMember(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('NewOwner', $roomId);
        $this->close($owner);

        $this->rooms->transferOwnership($this->staff('Admin', 'admin'), $roomId, $member->id, '127.0.0.9');

        self::assertSame([$member->id], $this->owners($roomId));
        self::assertSame('member', $this->role($roomId, $owner->id));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE kind = 'room_ownership_removed'"));
    }

    public function testAnAdministratorTakesOverAPrivateRoomWithoutJoiningFirst(): void
    {
        [$owner, $roomId] = $this->ownedRoom('private');
        $this->close($owner);
        $admin = $this->staff('ChatAdmin', 'chat_admin');
        $this->pdo->exec("INSERT INTO room_invitations (room_id, user_id, invited_by) VALUES ({$roomId}, {$admin->id}, {$owner->id})");

        $room = $this->rooms->transferOwnership($admin, $roomId, $admin->id, '127.0.0.9');

        self::assertSame('owner', $room->memberRole);
        self::assertSame([$admin->id], $this->owners($roomId));
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM room_invitations WHERE room_id = {$roomId}"), 'The invitation is consumed.');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM room_reads WHERE room_id = {$roomId} AND user_id = {$admin->id}"), 'The new membership starts a read position (#268).');
        self::assertEquals(
            ['from_user_id' => $owner->id, 'to_user_id' => $admin->id, 'joined' => true],
            json_decode((string) $this->scalar("SELECT metadata_json::text FROM audit_log WHERE action = 'room.ownership_transferred'"), true),
        );
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE kind = 'room_ownership_received'"), 'Nobody is told what they did themselves.');
    }

    public function testATakeoverKeepsTheRoomsMinimumAgeAndOnlyAdministratorsMayJoinThisWay(): void
    {
        [$owner, $roomId] = $this->ownedRoom('public', 18);
        $admin = $this->staff('ChatAdmin', 'chat_admin');
        $this->assertRefused(403, 'birth_date_required', fn () => $this->rooms->transferOwnership($admin, $roomId, $admin->id, '127.0.0.9'));
        $this->pdo->exec("UPDATE users SET birth_date = CURRENT_DATE - INTERVAL '17 years' WHERE id = {$admin->id}");
        $this->assertRefused(403, 'minimum_age_not_met', fn () => $this->rooms->transferOwnership($admin, $roomId, $admin->id, '127.0.0.9'));
        $this->pdo->exec("UPDATE users SET birth_date = CURRENT_DATE - INTERVAL '30 years' WHERE id = {$admin->id}");
        $this->rooms->transferOwnership($admin, $roomId, $admin->id, '127.0.0.9');
        self::assertSame([$admin->id], $this->owners($roomId));
        self::assertSame('moderator', $this->role($roomId, $owner->id));
    }

    public function testADeletedRoomCannotBeTransferred(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('NewOwner', $roomId);
        $admin = $this->staff('Admin', 'admin');
        $this->pdo->exec("UPDATE rooms SET deleted_at = NOW() WHERE id = {$roomId}");

        $this->assertRefused(404, 'room_not_found', fn () => $this->rooms->transferOwnership($admin, $roomId, $member->id, '127.0.0.9'));
        self::assertSame([$owner->id], $this->owners($roomId));
    }

    public function testTheDatabaseAllowsOneOwnerAndTheOwnerRowStaysOutOfReachOfOtherWrites(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('Member', $roomId);
        try {
            $this->pdo->exec("UPDATE room_members SET role = 'owner' WHERE room_id = {$roomId} AND user_id = {$member->id}");
            self::fail('A second owner must be refused.');
        } catch (PDOException $exception) {
            self::assertSame('23505', $exception->getCode());
        }

        $admin = $this->staff('Admin', 'admin');
        $this->assertRefused(409, 'owner_role_immutable', fn () => $this->rooms->setRole($admin, $roomId, $owner->id, 'member', '127.0.0.9'));
        $this->assertRefused(409, 'owner_cannot_leave', fn () => $this->rooms->leave($owner, $roomId, '127.0.0.9'));
        $this->assertRefused(409, 'owner_membership_immutable',
            fn () => (new \ChitChat\Admin\RoomAdminService($this->pdo))->removeMember($admin, $roomId, $owner->id, '127.0.0.9'));
        self::assertSame([$owner->id], $this->owners($roomId));
    }

    public function testMigration0058StopsWithTheRoomsNamedAndChangesNothing(): void
    {
        [$owner, $roomId] = $this->ownedRoom();
        $member = $this->member('Member', $roomId);
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/0058_room_ownership.sql');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DROP INDEX room_members_one_owner');
            $this->pdo->exec("UPDATE room_members SET role = 'owner' WHERE room_id = {$roomId} AND user_id = {$member->id}");
            $this->pdo->exec($sql);
            self::fail('The migration must stop on two owners.');
        } catch (PDOException $exception) {
            self::assertStringContainsString(
                "room {$roomId} (2 owners: user ids " . min($owner->id, $member->id) . ', ' . max($owner->id, $member->id) . ')',
                $exception->getMessage(),
            );
            self::assertStringContainsString('Nothing was changed.', $exception->getMessage());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'room_members_one_owner'"), 'The rollback restored the index.');
        self::assertSame([$owner->id], $this->owners($roomId));
    }

    /** @return array{0:AuthenticatedUser, 1:int} a room owner without a global role, and the room */
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

    private function close(AuthenticatedUser $user): void
    {
        $this->pdo->exec("UPDATE users SET account_state = 'closed', closure_requested_at = NOW() - INTERVAL '15 days',
            closure_finalizes_at = NOW() - INTERVAL '1 day', closed_at = NOW(), username = 'Closed account #{$user->id}' WHERE id = {$user->id}");
    }

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $fresh = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($fresh);

        return $fresh;
    }

    /** @return list<int> */
    private function owners(int $roomId): array
    {
        $statement = $this->pdo->query("SELECT user_id FROM room_members WHERE room_id = {$roomId} AND role = 'owner' ORDER BY user_id");
        self::assertNotFalse($statement);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function role(int $roomId, int $userId): ?string
    {
        $role = $this->pdo->query("SELECT role FROM room_members WHERE room_id = {$roomId} AND user_id = {$userId}")?->fetchColumn();

        return is_string($role) ? $role : null;
    }

    private function scalar(string $sql): mixed
    {
        return $this->pdo->query($sql)?->fetchColumn();
    }

    private function assertRefused(int $status, string $code, callable $action): void
    {
        try {
            $action();
            self::fail("Expected {$status} {$code}.");
        } catch (ApiException $exception) {
            self::assertSame([$status, $code], [$exception->status, $exception->errorCode]);
        }
    }
}
