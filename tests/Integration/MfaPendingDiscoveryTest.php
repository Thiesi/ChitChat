<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class MfaPendingDiscoveryTest extends DatabaseTestCase
{
    private TestSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    public function testDiscoveryShowsOnlyMethodsAndClearsExpiredOrWrongTransportContexts(): void
    {
        $user = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        TestPasskey::create()->seed($this->pdo, $user->id);
        $user = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($user);
        $mfa = new MfaService($this->pdo, $this->config);
        $csrf = SessionManager::csrfToken();
        self::assertNull($mfa->pendingSession('127.0.0.1'));
        self::assertSame($csrf, SessionManager::csrfToken());
        SessionManager::beginMfaLogin($user, '127.0.0.1', 300);
        $valid = $_SESSION;
        self::assertSame(['methods' => ['passkey', 'recovery_code']], $mfa->pendingSession('127.0.0.1'));
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertNull($mfa->pendingSession('127.0.0.2'));
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
        $_SESSION = $valid;
        $_SESSION['pending_mfa']['expires_at'] = time() - 1;
        $oldCsrf = SessionManager::csrfToken();
        self::assertNull($mfa->pendingSession('127.0.0.1'));
        self::assertNotSame($oldCsrf, SessionManager::csrfToken());
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
        $_SESSION = $valid;
        $this->pdo->exec('UPDATE users SET session_version = session_version + 1 WHERE id = ' . $user->id);
        self::assertNull($mfa->pendingSession('127.0.0.1'));
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
    }
}