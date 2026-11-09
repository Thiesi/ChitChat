<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthSession;
use ChitChat\Auth\SessionManager;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\TestSession;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeAuthSessionTest extends TestCase
{
    private TestSession $session;

    protected function setUp(): void
    {
        $this->session = new TestSession();
        $this->session->start();
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    public function testPrivateBindingsSurviveSameAccountRotationButBrowserAccountChangesInvalidateItsContext(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        $hash = NativeAuthSession::nativeHash(true);
        self::assertIsString($hash);
        self::assertNotSame(session_id(), $_SESSION['native_auth_binding']);
        $transaction = NativeConfig::token();
        NativeAuthSession::bindBrowser($transaction, $user);
        $browser = NativeAuthSession::browser($user);
        $sid = session_id();
        SessionManager::rotateForNative();
        self::assertNotSame($sid, session_id());
        self::assertSame($hash, NativeAuthSession::nativeHash());
        self::assertSame($browser, NativeAuthSession::browser($user));
        self::assertNull(NativeAuthSession::browser(new AuthenticatedUser(9, 'Other', [], 1)));
        self::assertSame($hash, NativeAuthSession::nativeHash());
    }

    public function testPrimaryLoginMfaAndPasswordResetDiscardAnOlderNativeIntent(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        foreach (['login', 'mfa', 'reset'] as $action) {
            NativeAuthSession::nativeHash(true);
            NativeAuthSession::setContinuation(NativeConfig::token(), 'provider-subject');
            match ($action) {
                'login' => SessionManager::login($user, new \ChitChat\Auth\IssuedLogin(str_repeat('A', 43), $user->id, $user->sessionVersion, str_repeat('a', 64))),
                'mfa' => SessionManager::beginMfaLogin($user, '192.0.2.1', 300),
                default => SessionManager::beginPasswordReset($user, password_hash('fixture password', PASSWORD_DEFAULT),
                    str_repeat('a', 32), '192.0.2.1', 300),
            };
            self::assertNull(NativeAuthSession::nativeHash(), $action);
            self::assertNull(NativeAuthSession::continuation(), $action);
        }
    }

    public function testMfaCarriesOnlyTheHashedReplacedLoginUntilFullCompletion(): void
    {
        $old = new AuthenticatedUser(2, 'Previous', [], 3);
        $next = new AuthenticatedUser(3, 'Next', [], 1);
        $issued = new \ChitChat\Auth\IssuedLogin(str_repeat('A', 43), $old->id, $old->sessionVersion, str_repeat('a', 64));
        SessionManager::login($old, $issued);
        $expected = ['id' => $issued->id, 'user_id' => $old->id, 'hash' => hash('sha256', $issued->token)];
        SessionManager::beginMfaLogin($next, '192.0.2.1', 300);
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertSame($expected, $_SESSION['pending_mfa']['replaced_login']);
        self::assertSame($expected, SessionManager::replacedLoginBinding());
        self::assertStringNotContainsString($issued->token, json_encode($_SESSION['pending_mfa'], JSON_THROW_ON_ERROR));
        SessionManager::beginMfaLogin($next, '192.0.2.1', 300);
        self::assertSame($expected, SessionManager::replacedLoginBinding(), 'A restarted factor does not lose the original browser holder.');
        SessionManager::login($next, new \ChitChat\Auth\IssuedLogin(str_repeat('B', 43), $next->id, $next->sessionVersion, str_repeat('b', 64)));
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
        self::assertSame(str_repeat('B', 43), SessionManager::loginBinding()['id']);
    }

    public function testNativeMfaIsBoundToItsTransactionAndSessionRatherThanItsNetwork(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        $binding = NativeAuthSession::nativeHash(true);
        $transaction = NativeConfig::token();
        NativeAuthSession::setContinuation($transaction, 'subject');
        SessionManager::beginMfaLogin($user, '192.0.2.1', 300, 'login', $transaction);
        SessionManager::assertPendingMfaTransport('198.51.100.2');
        self::assertSame($binding, NativeAuthSession::nativeHash());
        self::assertSame($transaction, $_SESSION['pending_mfa']['native_transaction']);
        self::assertArrayNotHasKey('auth', $_SESSION);
        NativeAuthSession::clearContinuation();
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('context changed');
        SessionManager::assertPendingMfaTransport('198.51.100.2');
    }

    public function testOrdinaryWebMfaKeepsItsExistingIpPin(): void
    {
        $user = new AuthenticatedUser(2, 'Member', [], 3);
        SessionManager::beginMfaLogin($user, '192.0.2.1', 300);
        SessionManager::assertPendingMfaTransport('192.0.2.1');
        try {
            SessionManager::assertPendingMfaTransport('198.51.100.2');
            self::fail('Web MFA transport unexpectedly changed.');
        } catch (ApiException $exception) {
            self::assertSame('mfa_login_expired', $exception->errorCode);
        }
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
    }
}
