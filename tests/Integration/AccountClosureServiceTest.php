<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;

final class AccountClosureServiceTest extends DatabaseTestCase
{
    private const PASSWORD = 'Account-Closure-Test-Aa1!';

    public function testClosureImmediatelyDisablesAuthenticationAndRestorationRecoversRoles(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('ClosureAdmin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('ClosureMember', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$member->id}, 'chat_admin')");
        $member = (new UserRepository($this->pdo))->findAuthenticatedById($member->id);
        self::assertNotNull($member);

        $service = new AccountClosureService($this->pdo, $this->config);
        $closure = $service->request($member, '127.0.0.2');

        self::assertSame('closure_pending', $closure['state']);
        self::assertSame(14, $closure['cooling_off_days']);
        self::assertNull((new UserRepository($this->pdo))->findAuthenticatedById($member->id));
        self::assertSame([], (new UserRepository($this->pdo))->rolesForUser($member->id));

        try {
            $auth->login('ClosureMember', self::PASSWORD, '127.0.0.2');
            self::fail('Pending account login unexpectedly succeeded.');
        } catch (ApiException $exception) {
            self::assertSame('account_closure_pending', $exception->errorCode);
        }

        $restored = $service->restore('ClosureMember', self::PASSWORD, '127.0.0.2');
        self::assertSame($member->id, $restored->id);
        self::assertContains('chat_admin', $restored->roles);
        self::assertSame(0, $service->dueCount());

        $loggedIn = $auth->login('ClosureMember', self::PASSWORD, '127.0.0.2');
        self::assertSame($member->id, $loggedIn->id);
    }

    public function testFinalizationTombstonesProfileAndReleasesOriginalUsername(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('LifecycleAdmin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('ReusableName', self::PASSWORD, '127.0.0.2', '1990-01-02');
        $service = new AccountClosureService($this->pdo, $this->config);
        $service->request($member, '127.0.0.2');
        $this->expireClosure($member->id);

        self::assertSame(1, $service->dueCount());
        self::assertSame(1, $service->finalizeDue());

        $row = $this->pdo->query(
            "SELECT username, username_canonical, password_hash, birth_date, account_state, closed_at FROM users WHERE id = {$member->id}",
        )?->fetch();
        self::assertIsArray($row);
        self::assertSame('Closed account #' . $member->id, $row['username']);
        self::assertStringStartsWith('closed-' . $member->id . '-', (string) $row['username_canonical']);
        self::assertNull($row['birth_date']);
        self::assertSame('closed', $row['account_state']);
        self::assertNotNull($row['closed_at']);
        self::assertFalse(password_verify(self::PASSWORD, (string) $row['password_hash']));

        $replacement = $auth->register('ReusableName', self::PASSWORD, '127.0.0.3');
        self::assertNotSame($member->id, $replacement->id);
    }

    public function testFinalActiveSuperAdministratorCannotRequestClosure(): void
    {
        $admin = (new AuthService($this->pdo, $this->config))->register(
            'OnlyAdmin',
            self::PASSWORD,
            '127.0.0.1',
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('final active Super-Administrator');
        (new AccountClosureService($this->pdo, $this->config))->request($admin, '127.0.0.1');
    }

    public function testExpiredPendingClosureCannotBeRestoredBeforeMaintenanceRuns(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('ExpiryAdmin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('ExpiryMember', self::PASSWORD, '127.0.0.2');
        $service = new AccountClosureService($this->pdo, $this->config);
        $service->request($member, '127.0.0.2');
        $this->expireClosure($member->id);

        try {
            $service->restore('ExpiryMember', self::PASSWORD, '127.0.0.2');
            self::fail('Expired account restoration unexpectedly succeeded.');
        } catch (ApiException $exception) {
            self::assertSame('account_restoration_expired', $exception->errorCode);
        }
    }

    private function expireClosure(int $userId): void
    {
        $this->pdo->exec(<<<SQL
UPDATE users
SET closure_requested_at = NOW() - INTERVAL '2 days',
    closure_finalizes_at = NOW() - INTERVAL '1 day'
WHERE id = {$userId}
SQL);
        $this->pdo->exec(<<<SQL
UPDATE account_closures
SET requested_at = NOW() - INTERVAL '2 days',
    finalizes_at = NOW() - INTERVAL '1 day'
WHERE user_id = {$userId}
SQL);
    }
}
