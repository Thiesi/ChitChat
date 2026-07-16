<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\RoomAdminService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Presence\PresenceService;
use ChitChat\Room\RoomService;

final class RoomAdminServiceTest extends DatabaseTestCase
{
    private const CONNECTION = '11111111-1111-4111-8111-111111111111';

    public function testSnapshotIncludesMembersPresenceAndInvitations(): void
    {
        [$owner, $member, $candidate, , $room] = $this->fixture();
        (new PresenceService($this->pdo, $this->config))->heartbeat(
            $member,
            self::CONNECTION,
            $room->id,
            true,
        );
        (new RoomService($this->pdo))->invite($owner, $room->id, $candidate->id, '127.0.0.1');

        $snapshot = (new RoomAdminService($this->pdo))->snapshot($owner, $room->id);

        self::assertSame($room->id, $snapshot['room']['id']);
        self::assertSame(['Root', 'Member'], array_column($snapshot['members'], 'username'));
        self::assertSame(['owner', 'member'], array_column($snapshot['members'], 'role'));
        self::assertSame(1, $snapshot['members'][1]['active_connections']);
        self::assertSame(['Candidate'], array_column($snapshot['invitations'], 'username'));
    }

    public function testInvitableSearchExcludesMembersAndPendingInvitations(): void
    {
        [$owner, , $candidate, $outsider, $room] = $this->fixture();
        $rooms = new RoomService($this->pdo);
        $rooms->invite($owner, $room->id, $candidate->id, '127.0.0.1');
        $admin = new RoomAdminService($this->pdo);

        self::assertSame(
            [['id' => $outsider->id, 'username' => 'Outsider']],
            $admin->searchInvitable($owner, $room->id, 'Out'),
        );
        self::assertSame([], $admin->searchInvitable($owner, $room->id, 'Can'));
    }

    public function testMemberRemovalClearsPresenceButPreservesAccount(): void
    {
        [$owner, $member, , , $room] = $this->fixture();
        (new PresenceService($this->pdo, $this->config))->heartbeat(
            $member,
            self::CONNECTION,
            $room->id,
            true,
        );

        $admin = new RoomAdminService($this->pdo);
        $admin->removeMember($owner, $room->id, $member->id, '127.0.0.1');

        self::assertNull((new RoomService($this->pdo))->get($member, $room->id)->memberRole);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM room_presence')?->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'room.member_removed'",
        )?->fetchColumn());

        try {
            $admin->removeMember($owner, $room->id, $owner->id, '127.0.0.1');
            self::fail('Expected immutable owner membership.');
        } catch (ApiException $exception) {
            self::assertSame('owner_membership_immutable', $exception->errorCode);
        }
    }

    public function testInvitationCanBeRevokedAndOutsiderCannotAdministerRoom(): void
    {
        [$owner, , $candidate, $outsider, $room] = $this->fixture();
        (new RoomService($this->pdo))->invite($owner, $room->id, $candidate->id, '127.0.0.1');
        $admin = new RoomAdminService($this->pdo);
        $admin->revokeInvitation($owner, $room->id, $candidate->id, '127.0.0.1');

        self::assertSame([], $admin->snapshot($owner, $room->id)['invitations']);

        try {
            $admin->snapshot($outsider, $room->id);
            self::fail('Expected room administration permission rejection.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
    }

    /** @return array{\ChitChat\Auth\AuthenticatedUser, \ChitChat\Auth\AuthenticatedUser, \ChitChat\Auth\AuthenticatedUser, \ChitChat\Auth\AuthenticatedUser, \ChitChat\Room\Room} */
    private function fixture(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $candidate = $auth->register('Candidate', 'different secure password', '127.0.0.3');
        $outsider = $auth->register('Outsider', 'further secure password', '127.0.0.4');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');

        return [$owner, $member, $candidate, $outsider, $room];
    }
}
