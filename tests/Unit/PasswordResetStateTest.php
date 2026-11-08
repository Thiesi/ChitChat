<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\SessionManager;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PasswordResetStateTest extends TestCase
{
    private \ChitChat\Tests\Support\TestSession $session;

    protected function setUp(): void
    {
        $this->session = new \ChitChat\Tests\Support\TestSession();
        $this->session->start();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->session->stop();
        parent::tearDown();
    }

    public function testResetHasItsOwnPendingStateAndClearsAuthenticationAndElevation(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        $_SESSION = ['auth' => ['user_id' => 2], 'privileged_step_up' => ['user_id' => 2],
            'pending_mfa' => ['flow' => 'login'], 'webauthn_ceremony' => ['purpose' => 'mfa_login']];
        $hash = password_hash('a secure test password', PASSWORD_DEFAULT);
        SessionManager::beginPasswordReset($user, $hash, str_repeat('a', 32), '192.0.2.1', 300);
        $state = SessionManager::pendingPasswordReset();
        self::assertSame(2, $state['user_id']);
        self::assertSame(3, $state['session_version']);
        self::assertSame($hash, $state['password_hash']);
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
        self::assertArrayNotHasKey('webauthn_ceremony', $_SESSION);
        self::assertArrayNotHasKey('recovery_key', $state);
    }

    public function testLoginAndOrdinaryMfaReplaceTheResetState(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        $hash = password_hash('a secure test password', PASSWORD_DEFAULT);
        $_SESSION = [];
        SessionManager::beginPasswordReset($user, $hash, str_repeat('a', 32), '192.0.2.1', 300);
        SessionManager::beginMfaLogin($user, '192.0.2.1', 300);
        self::assertArrayNotHasKey('pending_password_reset', $_SESSION);
        SessionManager::beginPasswordReset($user, $hash, str_repeat('a', 32), '192.0.2.1', 300);
        SessionManager::login($user);
        self::assertArrayNotHasKey('pending_password_reset', $_SESSION);
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
    }

    public function testExpiredResetIsClearedAndCannotBecomeAnAuthenticatedUser(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        $_SESSION = [];
        SessionManager::beginPasswordReset($user, password_hash('a secure test password', PASSWORD_DEFAULT),
            str_repeat('a', 32), '192.0.2.1', 300);
        $_SESSION['pending_password_reset']['expires_at'] = time() - 1;
        try {
            SessionManager::pendingPasswordReset();
            self::fail('Expected an expired reset to fail.');
        } catch (ApiException $exception) {
            self::assertSame('reset_failed', $exception->errorCode);
        }
        self::assertArrayNotHasKey('pending_password_reset', $_SESSION);
        self::assertArrayNotHasKey('auth', $_SESSION);
    }
}
