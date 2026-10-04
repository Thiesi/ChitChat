<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;

final class ModerationRankTest extends DatabaseTestCase
{
    public function testModeratorsDeleteOnlyMessagesOfLowerRanks(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $owner = $auth->register('Owner', 'another secure password', '127.0.0.2');
        $moderator = $auth->register('Moderator', 'one more secure password', '127.0.0.3');
        $member = $auth->register('Member', 'yet another secure password', '127.0.0.4');
        $staff = $auth->register('Staff', 'still another secure password', '127.0.0.5');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin'), ({$staff->id}, 'global_moderator')");
        $owner = $this->reload($owner);
        $staff = $this->reload($staff);

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.2');
        // The owner is staff too; take that away so the owner's room rank is what counts.
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $owner = $this->reload($owner);
        foreach ([$moderator, $member, $staff] as $person) {
            $rooms->join($person, $room->id, '127.0.0.9');
        }
        $rooms->setRole($owner, $room->id, $moderator->id, 'moderator', '127.0.0.2');
        $moderator = $this->reload($moderator);

        $messages = new MessageService($this->pdo);
        $byOwner = $messages->send($owner, $room->id, 'From the owner');
        $byMember = $messages->send($member, $room->id, 'From a member');
        $byStaff = $messages->send($staff, $room->id, 'From a global moderator');
        $byModerator = $messages->send($moderator, $room->id, 'From a room moderator');

        // What the browser is told: a room moderator may delete only the member's message.
        $flags = $this->moderatable($moderator, $room->id, [$byOwner['id'], $byMember['id'], $byStaff['id'], $byModerator['id']]);
        self::assertSame([$byOwner['id'] => false, $byMember['id'] => true, $byStaff['id'] => false, $byModerator['id'] => false], $flags);
        // The owner outranks room moderators and members, not global staff.
        $flags = $this->moderatable($owner, $room->id, [$byMember['id'], $byStaff['id'], $byModerator['id']]);
        self::assertSame([$byMember['id'] => true, $byStaff['id'] => false, $byModerator['id'] => true], $flags);
        // A Global Moderator outranks the owner; a Super-Administrator everyone.
        self::assertTrue($this->moderatable($staff, $room->id, [$byOwner['id']])[$byOwner['id']]);
        self::assertTrue($this->moderatable($root, $room->id, [$byStaff['id']])[$byStaff['id']]);

        // The server enforces the same rule, whatever the browser shows.
        $this->assertForbidden(fn () => $messages->delete($moderator, $byOwner['id'], '127.0.0.3'));
        $this->assertForbidden(fn () => $messages->delete($owner, $byStaff['id'], '127.0.0.2'));
        $messages->delete($moderator, $byMember['id'], '127.0.0.3', 'Off topic');
        self::assertSame(
            'Off topic',
            $this->pdo->query("SELECT metadata_json->>'reason' FROM audit_log WHERE action = 'room.message_deleted'")?->fetchColumn(),
        );
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE user_id = {$member->id} AND kind = 'moderator_message_deleted'")?->fetchColumn());
    }

    public function testOnlySuperAdministratorsActOnAdministrators(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $first = $auth->register('FirstAdmin', 'another secure password', '127.0.0.2');
        $second = $auth->register('SecondAdmin', 'one more secure password', '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$first->id}, 'admin'), ({$second->id}, 'admin')");
        $first = $this->reload($first);
        $moderation = new ModerationService($this->pdo);

        $this->assertForbidden(fn () => $moderation->kick($first, $second->id, 'Testing', '127.0.0.2'));
        $this->assertForbidden(fn () => $moderation->ban($first, $second->id, 'Testing', null, '127.0.0.2'));
        $moderation->kick($root, $second->id, 'Testing', '127.0.0.1');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'moderation.kick'")?->fetchColumn());
    }

    /**
     * @param list<int> $messageIds
     * @return array<int, bool>
     */
    private function moderatable(AuthenticatedUser $viewer, int $roomId, array $messageIds): array
    {
        $flags = [];
        foreach ((new RoomMessageMutationService($this->pdo))->metadata($viewer, $roomId, $messageIds) as $message) {
            $flags[$message['id']] = $message['can_moderate'];
        }

        return $flags;
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

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $reloaded = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }
}
