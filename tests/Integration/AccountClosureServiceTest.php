<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Admin\SystemSettingsService;
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

    public function testRestoreAuthenticationDoesNotMutateAccountBeforeCompletion(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('PendingAdmin', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('PendingMember', self::PASSWORD, '127.0.0.2');
        $service = new AccountClosureService($this->pdo, $this->config);
        $service->request($member, '127.0.0.2');

        $pending = $service->authenticateRestore('PendingMember', self::PASSWORD, '127.0.0.2');
        self::assertSame($member->id, $pending->id);
        self::assertNull((new UserRepository($this->pdo))->findAuthenticatedById($member->id));
        self::assertSame('closure_pending', $this->pdo->query(
            "SELECT account_state FROM users WHERE id = {$member->id}",
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM account_closures WHERE user_id = {$member->id} AND restored_at IS NOT NULL",
        )->fetchColumn());

        $restored = $service->completeRestore($pending->id, '127.0.0.2');
        self::assertSame($member->id, $restored->id);
        self::assertSame('active', $this->pdo->query(
            "SELECT account_state FROM users WHERE id = {$member->id}",
        )->fetchColumn());
    }

    public function testCurrentMfaPolicyCanWithholdProtectedRoleSnapshotDuringRestore(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('PolicyRoot', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('PolicyMember', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$member->id}, 'chat_admin')");
        $member = (new UserRepository($this->pdo))->findAuthenticatedById($member->id);
        self::assertNotNull($member);

        $service = new AccountClosureService($this->pdo, $this->config);
        $service->request($member, '127.0.0.2');
        $this->seedPasskeyMfa($root->id);
        (new SystemSettingsService($this->pdo))->update(
            actor: $root,
            registrationEnabled: true,
            mfaRequiredForAdminRoles: true,
            roomMessageRetentionDays: 0,
            directMessageRetentionDays: 0,
            auditRetentionDays: 0,
            deletedAttachmentRetentionDays: 30,
            orphanAttachmentGraceHours: 24,
            realtimeEventRetentionHours: 168,
            loginAttemptRetentionDays: 30,
            ipAddress: '127.0.0.1',
        );

        $restored = $service->completeRestore($member->id, '127.0.0.2');
        self::assertSame([], $restored->roles);
        $metadata = $this->pdo->query(
            "SELECT metadata_json::text FROM audit_log WHERE action = 'account.closure_restored' ORDER BY id DESC LIMIT 1",
        )->fetchColumn();
        self::assertIsString($metadata);
        self::assertStringContainsString('chat_admin', $metadata);
        self::assertStringContainsString('withheld_roles', $metadata);
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

    private function seedPasskeyMfa(int $userId): void
    {
        $this->pdo->exec(sprintf(
            "UPDATE users SET mfa_enabled_at = NOW(), webauthn_user_handle = decode(repeat('ab', 32), 'hex') WHERE id = %d",
            $userId,
        ));
        $this->pdo->exec(sprintf(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key_cose, algorithm, label) VALUES (%d, decode(repeat('01', 32), 'hex'), decode('a0', 'hex'), -7, 'Policy key')",
            $userId,
        ));
        $this->pdo->exec(sprintf(
            "INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (%d, decode(repeat('cd', 32), 'hex'))",
            $userId,
        ));
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
