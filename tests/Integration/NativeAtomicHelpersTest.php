<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\NativeAuth\NativeAuthGuard;
use ChitChat\Auth\UserRepository;
use ChitChat\Tests\Support\TestPasskey;

final class NativeAtomicHelpersTest extends DatabaseTestCase
{
    public function testProviderRegistrationDoesNotCommitItsCallersTransaction(): void
    {
        $_SESSION = [];
        $this->pdo->beginTransaction();
        (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy(null);
        $user = (new AuthService($this->pdo, $this->config))->registerWithProviderInTransaction(
            'NativeMember', 'google', 'private-subject', '192.0.2.1');
        self::assertTrue($this->pdo->inTransaction());
        self::assertNotNull((new UserRepository($this->pdo))->findAuthenticatedById($user->id));
        $this->pdo->rollBack();
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_identities')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testRestorationRolesAndAuditRollBackWithTheOuterNativeTransaction(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $closure = new AccountClosureService($this->pdo, $this->config);
        $closure->request($member, '192.0.2.2');
        $users = new UserRepository($this->pdo);
        $pending = $users->findClosurePendingById($member->id);
        self::assertNotNull($pending);
        $this->pdo->beginTransaction();
        (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy($member->id);
        $restored = $closure->completeRestoreInTransaction($member->id, '192.0.2.2');
        self::assertTrue($this->pdo->inTransaction());
        self::assertGreaterThan($pending->sessionVersion, $restored->sessionVersion);
        $this->pdo->rollBack();
        self::assertNull($users->findAuthenticatedById($member->id));
        self::assertSame($pending->sessionVersion, $users->findClosurePendingById($member->id)?->sessionVersion);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.closure_restored'")->fetchColumn());
    }

    public function testNativePasskeyCounterAndSuccessAuditCommitOnlyWithTheGuardedCallback(): void
    {
        $_SESSION = [];
        $user = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '192.0.2.1');
        $key = TestPasskey::create();
        $key->seed($this->pdo, $user->id);
        $config = $this->configWith(['webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test']);
        $mfa = new MfaService($this->pdo, $config);
        $options = $mfa->beginAssertion($user, 'mfa_login', '192.0.2.1');
        try {
            $mfa->completeNativeWithAssertion($user, $key->assertion($options, 1), '192.0.2.1',
                function (callable $verify) use ($user, $config): array {
                    (new NativeAuthGuard($this->pdo, $config))->lockAccountPolicy($user->id);
                    $verify();
                    throw new \RuntimeException('Native rollback fixture.');
                });
            self::fail('Expected rollback fixture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Native rollback fixture.', $exception->getMessage());
        }
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(0, (int) $this->pdo->query('SELECT sign_count FROM webauthn_credentials')->fetchColumn());
        $options = $mfa->beginAssertion($user, 'mfa_login', '198.51.100.2');
        $result = $mfa->completeNativeWithAssertion($user, $key->assertion($options, 1), '198.51.100.2',
            function (callable $verify) use ($user, $config): array {
                (new NativeAuthGuard($this->pdo, $config))->lockAccountPolicy($user->id);
                $verify();
                return ['verified' => true];
            });
        self::assertSame(['verified' => true], $result);
        self::assertSame(1, (int) $this->pdo->query('SELECT sign_count FROM webauthn_credentials')->fetchColumn());
        self::assertArrayNotHasKey('auth', $_SESSION, 'Only the native completion service may install authentication after commit.');
    }

    public function testNativeRecoveryCodeConsumptionRollsBackWithTheOuterGuard(): void
    {
        $_SESSION = [];
        $user = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '192.0.2.1');
        $key = TestPasskey::create();
        $key->seed($this->pdo, $user->id);
        $mfa = new MfaService($this->pdo, $this->config);
        try {
            $mfa->completeNativeWithRecoveryCode($user, $key->recoveryCode, '192.0.2.1',
                function (callable $verify) use ($user): array {
                    (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy($user->id);
                    $verify();
                    throw new \RuntimeException('Native rollback fixture.');
                });
            self::fail('Expected rollback fixture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Native rollback fixture.', $exception->getMessage());
        }
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        $mfa->completeNativeWithRecoveryCode($user, $key->recoveryCode, '198.51.100.2',
            function (callable $verify) use ($user): array {
                (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy($user->id);
                $verify();
                return ['verified' => true];
            });
        self::assertSame(0, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        self::assertArrayNotHasKey('auth', $_SESSION);
    }
}
