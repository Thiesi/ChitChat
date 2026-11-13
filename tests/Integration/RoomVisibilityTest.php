<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\RoomAdminService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\MuteService;
use ChitChat\Moderation\ReportService;
use ChitChat\Presence\PresenceService;
use ChitChat\Reactions\RoomReactionService;
use ChitChat\Realtime\BroadcastService;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;
use ChitChat\Upload\AttachmentMetadataService;
use Closure;
use DateTimeImmutable;

/**
 * A room the caller can't see answers exactly like one that doesn't exist (#326): for a private
 * room without membership or invitation, every room route gives the same status, code and
 * message as for a missing room ID, and routes addressed by a message ID the same as for a
 * missing message. Callers who can see the room keep their existing answers.
 */
final class RoomVisibilityTest extends DatabaseTestCase
{
    private const MISSING = 999_999;
    private const PASSWORD = 'Room-Visibility-Test-Aa1!';

    private AuthService $auth;
    private RoomService $rooms;
    private AuthenticatedUser $admin;
    private AuthenticatedUser $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = new AuthService($this->pdo, $this->config);
        $this->rooms = new RoomService($this->pdo);
        // The first account is the Super-Administrator; the outsider has no role.
        $this->admin = $this->auth->register('Admin', self::PASSWORD, '127.0.0.1');
        $this->outsider = $this->auth->register('Outsider', 'An outside caller passphrase 1!', '127.0.0.2');
    }

    public function testEveryRoomRouteAnswersAnInvisiblePrivateRoomLikeAMissingOne(): void
    {
        $private = $this->rooms->create($this->admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $message = (new MessageService($this->pdo))->send($this->admin, $private->id, 'Members only');

        $actor = $this->outsider;
        $routes = [
            'rooms/detail' => fn (int $room): mixed => $this->rooms->get($actor, $room),
            'rooms/pings' => fn (int $room): mixed => (new PingService($this->pdo))->history($actor, $room),
            'rooms/messages' => fn (int $room): mixed => (new MessageService($this->pdo))->history($actor, $room),
            'rooms/message-mutations' => fn (int $room): mixed => (new RoomMessageMutationService($this->pdo))->metadata($actor, $room, [1]),
            'attachments/metadata' => fn (int $room): mixed => (new AttachmentMetadataService($this->pdo))->forMessages($actor, $room, [1]),
            'rooms/mentionable-users' => fn (int $room): mixed => (new MessageService($this->pdo))->searchMentionableUsers($actor, $room, 'ad'),
            'rooms/send' => fn (int $room): mixed => (new MessageService($this->pdo))->send($actor, $room, 'Hello'),
            'rooms/send (ping)' => fn (int $room): mixed => (new PingService($this->pdo))->send($actor, $room, 'Admin'),
            'rooms/join' => fn (int $room): mixed => $this->rooms->join($actor, $room, '127.0.0.2'),
            'rooms/leave' => fn (int $room): mixed => $this->rooms->leave($actor, $room, '127.0.0.2'),
            'presence/heartbeat' => fn (int $room): mixed => (new PresenceService($this->pdo, $this->config))->heartbeat($actor, '0b8f8f3e-6f6a-4c3a-9a45-2f1f2b4a7c11', $room, true),
            'rooms/presence' => fn (int $room): mixed => (new PresenceService($this->pdo, $this->config))->list($actor, $room),
            'rooms/update' => fn (int $room): mixed => $this->rooms->update($actor, $room, 'Renamed', '', 'private', 0, 0, '127.0.0.2'),
            'rooms/delete' => fn (int $room): mixed => $this->rooms->delete($actor, $room, '127.0.0.2'),
            'rooms/role' => fn (int $room): mixed => $this->rooms->setRole($actor, $room, $this->admin->id, 'moderator', '127.0.0.2'),
            'rooms/invite' => fn (int $room): mixed => $this->rooms->invite($actor, $room, $this->admin->id, '127.0.0.2'),
            'rooms/transfer-ownership' => fn (int $room): mixed => $this->rooms->transferOwnership($actor, $room, $actor->id, '127.0.0.2'),
            'admin/rooms/snapshot' => fn (int $room): mixed => (new RoomAdminService($this->pdo))->snapshot($actor, $room),
            'admin/rooms/search-users' => fn (int $room): mixed => (new RoomAdminService($this->pdo))->searchInvitable($actor, $room, 'ad'),
            'admin/rooms/remove-member' => fn (int $room): mixed => (new RoomAdminService($this->pdo))->removeMember($actor, $room, $this->admin->id, '127.0.0.2'),
            'admin/rooms/revoke-invitation' => fn (int $room): mixed => (new RoomAdminService($this->pdo))->revokeInvitation($actor, $room, $this->admin->id, '127.0.0.2'),
            'room broadcast' => fn (int $room): mixed => (new BroadcastService($this->pdo))->room($actor, $room, 'Hello', '127.0.0.2'),
            'room mute' => fn (int $room): mixed => (new MuteService($this->pdo))->mute($actor, $this->admin->id, $room, null, 'Reason', false, '127.0.0.2'),
        ];
        foreach ($routes as $route => $call) {
            $this->assertSameRefusal($route, $call, $private->id, self::MISSING, [404, 'room_not_found', 'Room not found.']);
        }

        $byMessage = [
            'rooms/react' => fn (int $id): mixed => (new RoomReactionService($this->pdo))->add($actor, $id, '👍'),
            'rooms/unreact' => fn (int $id): mixed => (new RoomReactionService($this->pdo))->remove($actor, $id, '👍'),
            'rooms/edit-message' => fn (int $id): mixed => (new RoomMessageMutationService($this->pdo))->edit($actor, $id, 'Changed', '127.0.0.2'),
            'rooms/delete-own-message' => fn (int $id): mixed => (new RoomMessageMutationService($this->pdo))->deleteOwn($actor, $id, '127.0.0.2'),
            'rooms/delete-message' => fn (int $id): mixed => (new MessageService($this->pdo))->delete($actor, $id, '127.0.0.2'),
            'reports/message' => fn (int $id): mixed => (new ReportService($this->pdo))->reportRoomMessage($actor, $id, 'spam', null, '127.0.0.2'),
        ];
        foreach ($byMessage as $route => $call) {
            $this->assertSameRefusal($route, $call, (int) $message['id'], self::MISSING, [404, 'message_not_found', 'Message not found.']);
        }
    }

    public function testCallersWhoCanSeeTheRoomKeepTheirAnswers(): void
    {
        $private = $this->rooms->create($this->admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');

        // An invitee sees the room: history still needs joining, and the invitation works.
        $this->rooms->invite($this->admin, $private->id, $this->outsider->id, '127.0.0.1');
        $this->assertRefusal([403, 'room_forbidden'], fn (): mixed => (new MessageService($this->pdo))->history($this->outsider, $private->id));
        $this->assertRefusal([403, 'membership_required'], fn (): mixed => (new MessageService($this->pdo))->send($this->outsider, $private->id, 'Hi'));
        self::assertSame('member', $this->rooms->join($this->outsider, $private->id, '127.0.0.2')->memberRole);

        // A member who isn't the owner may not manage.
        $member = $this->reload($this->outsider);
        $this->assertRefusal([403, 'forbidden'], fn (): mixed => $this->rooms->update($member, $private->id, 'Renamed', '', 'private', 0, 0, '127.0.0.2'));

        // A global moderator sees every room.
        $moderator = $this->auth->register('Moderator', 'A steady watchful passphrase 2!', '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        self::assertIsArray((new MessageService($this->pdo))->history($this->reload($moderator), $private->id));
    }

    public function testARemovedAuthorGetsMessageNotFoundNotAConfirmation(): void
    {
        $private = $this->rooms->create($this->admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $this->rooms->invite($this->admin, $private->id, $this->outsider->id, '127.0.0.1');
        $this->rooms->join($this->outsider, $private->id, '127.0.0.2');
        $mine = (new MessageService($this->pdo))->send($this->outsider, $private->id, 'Mine');
        (new RoomAdminService($this->pdo))->removeMember($this->admin, $private->id, $this->outsider->id, '127.0.0.1');

        $removed = $this->reload($this->outsider);
        foreach ([
            fn (): mixed => (new RoomMessageMutationService($this->pdo))->edit($removed, (int) $mine['id'], 'Changed', '127.0.0.2'),
            fn (): mixed => (new RoomMessageMutationService($this->pdo))->deleteOwn($removed, (int) $mine['id'], '127.0.0.2'),
        ] as $call) {
            $this->assertRefusal([404, 'message_not_found', 'Message not found.'], $call);
        }
        $this->assertRefusal([404, 'room_not_found', 'Room not found.'], fn (): mixed => (new MessageService($this->pdo))->history($removed, $private->id));
    }

    public function testPublicAndUnlistedRoomsAreUnchanged(): void
    {
        $unlisted = $this->rooms->create($this->admin, 'unlisted', 'Unlisted', '', 'unlisted', 0, 0, '127.0.0.1');
        self::assertIsArray((new MessageService($this->pdo))->history($this->outsider, $unlisted->id), 'Readable by ID.');
        self::assertSame('member', $this->rooms->join($this->outsider, $unlisted->id, '127.0.0.2')->memberRole, 'Joinable by ID.');

        // The age gate on a room the caller can see still says so.
        $adults = $this->rooms->create($this->admin, 'adults', 'Adults', '', 'public', 18, 0, '127.0.0.1');
        $minor = $this->auth->register('Minor', 'A younger caller passphrase 3!', '127.0.0.4', (new DateTimeImmutable('-15 years'))->format('Y-m-d'));
        $this->assertRefusal([403, 'minimum_age_not_met'], fn (): mixed => $this->rooms->join($minor, $adults->id, '127.0.0.4'));
    }

    /**
     * @param Closure(int): mixed $call
     * @param array{0:int, 1:string, 2:string} $expected
     */
    private function assertSameRefusal(string $route, Closure $call, int $hidden, int $missing, array $expected): void
    {
        $answers = [];
        foreach (['hidden' => $hidden, 'missing' => $missing] as $case => $id) {
            try {
                $call($id);
                self::fail("{$route}: the {$case} case was allowed.");
            } catch (ApiException $exception) {
                $answers[$case] = [$exception->status, $exception->errorCode, $exception->getMessage()];
            }
        }
        self::assertSame($answers['missing'], $answers['hidden'], "{$route}: an invisible room must answer exactly like a missing one.");
        self::assertSame($expected, $answers['hidden'], $route);
    }

    /**
     * @param array{0:int, 1:string, 2?:string} $expected
     * @param Closure(): mixed $call
     */
    private function assertRefusal(array $expected, Closure $call): void
    {
        try {
            $call();
            self::fail('Expected ' . implode(' ', $expected) . '.');
        } catch (ApiException $exception) {
            $actual = [$exception->status, $exception->errorCode];
            if (isset($expected[2])) {
                $actual[] = $exception->getMessage();
            }
            self::assertSame($expected, $actual);
        }
    }

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $fresh = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($fresh);

        return $fresh;
    }
}
