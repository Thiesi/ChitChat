<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\AdminService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationService;
use ChitChat\Realtime\EventRepository;

final class AdminServiceTest extends DatabaseTestCase
{
    public function testAdministratorsCanListUsersWithRolesAndActiveBans(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        (new ModerationService($this->pdo))->ban(
            $root,
            $member->id,
            'Testing',
            null,
            '127.0.0.1',
        );

        $users = (new AdminService($this->pdo))->listUsers($root);

        self::assertSame(['Root', 'Member'], array_column($users, 'username'));
        self::assertSame(['super_admin'], $users[0]['roles']);
        self::assertSame('Testing', $users[1]['active_ban']['reason'] ?? null);

        try {
            (new AdminService($this->pdo))->listUsers($member);
            self::fail('Expected non-administrator user listing rejection.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }
    }

    public function testRoleChangesAreAuditedAndInvalidateSessions(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');
        $oldVersion = $target->sessionVersion;

        $admin = new AdminService($this->pdo);
        $admin->setRoles($root, $target->id, ['chat_admin', 'admin'], '127.0.0.1');

        $reloaded = (new UserRepository($this->pdo))->findAuthenticatedById($target->id);
        self::assertNotNull($reloaded);
        self::assertSame(['admin', 'chat_admin'], $reloaded->roles);
        self::assertGreaterThan($oldVersion, $reloaded->sessionVersion);

        $targetEvents = (new EventRepository($this->pdo))->visibleAfter($target, 0);
        self::assertSame(['forced_logout'], array_map(
            static fn ($event): string => $event->type,
            $targetEvents,
        ));

        $entries = $admin->auditEntries($root);
        self::assertContains('admin.roles_changed', array_column($entries, 'action'));
    }

    public function testAdministratorCannotGrantSuperAdministratorRole(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $operator = $auth->register('Operator', 'another secure password', '127.0.0.2');
        $target = $auth->register('Target', 'different secure password', '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$operator->id}, 'admin')");
        $operator = (new UserRepository($this->pdo))->findAuthenticatedById($operator->id);
        self::assertNotNull($operator);

        try {
            (new AdminService($this->pdo))->setRoles(
                $operator,
                $target->id,
                ['super_admin'],
                '127.0.0.2',
            );
            self::fail('Expected Super-Administrator role escalation rejection.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        self::assertTrue($root->hasRole('super_admin'));
    }

    public function testStaleAdministrativeSessionCannotChangeRoles(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');
        $this->pdo->exec("UPDATE users SET session_version = session_version + 1 WHERE id = {$root->id}");

        try {
            (new AdminService($this->pdo))->setRoles(
                $root,
                $target->id,
                ['admin'],
                '127.0.0.1',
            );
            self::fail('Expected stale administrative session rejection.');
        } catch (ApiException $exception) {
            self::assertSame('authentication_required', $exception->errorCode);
        }

        self::assertSame([], (new UserRepository($this->pdo))->rolesForUser($target->id));
    }

    public function testUserSearchAndAuditPaginationAreBounded(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $auth->register('Alice', 'another secure password', '127.0.0.2');
        $auth->register('Alfred', 'different secure password', '127.0.0.3');
        $auth->register('Bob', 'further secure password', '127.0.0.4');
        $admin = new AdminService($this->pdo);

        self::assertSame(
            ['Alice', 'Alfred'],
            array_column($admin->listUsers($root, 'Al'), 'username'),
        );

        $firstPage = $admin->auditEntries($root, limit: 2);
        self::assertCount(2, $firstPage);
        $secondPage = $admin->auditEntries($root, beforeId: $firstPage[1]['id'], limit: 2);
        self::assertNotSame(
            array_column($firstPage, 'id'),
            array_column($secondPage, 'id'),
        );
    }
}
