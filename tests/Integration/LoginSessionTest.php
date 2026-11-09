<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\IssuedLogin;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\PushFixtures;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class LoginSessionTest extends DatabaseTestCase
{
    private TestSession $session;
    private LoginSessionRepository $logins;
    private AuthenticatedUser $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $this->logins = new LoginSessionRepository($this->pdo);
        $this->member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    private function signIn(?AuthenticatedUser $user = null): IssuedLogin
    {
        $_SESSION = []; // A separate browser holder, not a same-browser replacement.
        $user ??= $this->member;
        $issued = $this->logins->issue($user, 'Mozilla/5.0 (Windows NT 10.0) Firefox/145.0');
        SessionManager::login($user, $issued);
        return $issued;
    }

    public function testListUsesOpaqueIdsRoundedTimesAndNoCredentialsOrRawAgent(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();
        self::assertNotSame($first->id, $second->id);
        self::assertSame($this->member->id, SessionManager::requireUserOrGuest(new UserRepository($this->pdo))->id);
        $rows = $this->logins->list($this->member, $second->id);
        self::assertCount(2, $rows);
        self::assertSame([$second->id, $first->id], array_column($rows, 'id'));
        self::assertTrue($rows[0]['current']);
        self::assertFalse($rows[1]['current']);
        self::assertSame('Firefox on Windows', $rows[0]['client_hint']);
        self::assertNull($rows[0]['device_label']);
        self::assertStringContainsString(':00', $rows[0]['created_at']);
        self::assertStringContainsString(':00', $rows[0]['last_active_at']);
        self::assertSame(['id','current','created_at','last_active_at','client_hint','device_label'], array_keys($rows[0]));
        $stored = $this->pdo->query('SELECT * FROM login_sessions')->fetchAll();
        foreach ($stored as $row) {
            self::assertNotContains($first->token, $row);
            self::assertNotContains(session_id(), $row);
        }
        $before = $this->pdo->query("SELECT last_seen_at FROM login_sessions WHERE id = '" . $second->id . "'")->fetchColumn();
        SessionManager::currentUser(new UserRepository($this->pdo));
        self::assertSame($before, $this->pdo->query("SELECT last_seen_at FROM login_sessions WHERE id = '" . $second->id . "'")->fetchColumn());
    }

    public function testRevocationIsPrivateIdempotentAndKeepsTheCurrentHolderUnchanged(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();
        $sid = session_id();
        $auth = $_SESSION['auth'];
        $csrf = SessionManager::csrfToken();
        $this->fails('current_session', fn () => $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), $second->id, '127.0.0.1'));
        $other = (new AuthService($this->pdo, $this->config))->register('Other', 'different secure passphrase', '127.0.0.2');
        $_SESSION = [];
        $foreign = $this->logins->issue($other);
        $_SESSION['auth'] = $auth;
        $this->fails('session_not_found', fn () => $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), $foreign->id, '127.0.0.1'));
        self::assertSame(1, $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), $first->id, '127.0.0.1'));
        self::assertSame(0, $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), $first->id, '127.0.0.1'));
        self::assertSame($auth, $_SESSION['auth']);
        self::assertSame($sid, session_id());
        // Restore the separate-jar helper's CSRF so the assertion covers the bulk operation itself.
        $_SESSION['csrf_token'] = $csrf;
        self::assertSame(0, $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), null, '127.0.0.1'));
        self::assertSame($csrf, SessionManager::csrfToken());
        self::assertCount(1, $this->logins->list($this->member, $second->id));
        self::assertSame($this->member->sessionVersion, (new UserRepository($this->pdo))->findAuthenticatedById($this->member->id)?->sessionVersion);
    }

    public function testOnlyTheRevokedHoldersProofGetsTheDefinitiveReasonAndPruningCannotResurrectIt(): void
    {
        $first = $this->signIn();
        $firstAuth = $_SESSION['auth'];
        $second = $this->signIn();
        $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), $first->id, '127.0.0.1');
        $_SESSION['auth'] = $firstAuth;
        $_SESSION['auth']['push_token'] = str_repeat('0', 64);
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
        $_SESSION['auth'] = $firstAuth;
        $this->fails('session_revoked', fn () => SessionManager::currentUser(new UserRepository($this->pdo)));
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
        $this->pdo->exec("UPDATE login_sessions SET revoked_at = NOW() - interval '8 days' WHERE id = '" . $first->id . "'");
        self::assertSame(1, $this->logins->prune());
        $_SESSION['auth'] = $firstAuth;
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions')->fetchColumn());
        $_SESSION['auth'] = ['user_id' => $this->member->id, 'session_version' => $this->member->sessionVersion];
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
        $this->fails('authentication_required', fn () => SessionManager::pushBindingHash());
    }

    public function testSameAccountRotationPreservesTheRecordButFreshLoginRetiresTheReplacedOne(): void
    {
        $first = $this->signIn();
        $binding = SessionManager::loginBinding();
        $sid = session_id();
        SessionManager::rotateForNative();
        self::assertNotSame($sid, session_id());
        self::assertSame($binding, SessionManager::loginBinding());
        $replacement = $this->logins->issue($this->member);
        SessionManager::login($this->member, $replacement);
        self::assertSame('session_revoked', $this->logins->invalidReason($this->member->id, $this->member->sessionVersion, $first->id, hash('sha256', $first->token)));
        self::assertCount(1, $this->logins->list($this->member, $replacement->id));
    }

    public function testCapRetiresTheLeastRecentAndVersionInvalidationAtomicallyRemovesPush(): void
    {
        $first = $this->signIn();
        $this->pdo->exec("UPDATE login_sessions SET last_seen_at = NOW() - interval '1 day'");
        for ($i = 0; $i < 50; $i++) $last = $this->signIn();
        self::assertCount(50, $this->logins->list($this->member, $last->id));
        self::assertSame('session_revoked', $this->logins->invalidReason($this->member->id, $this->member->sessionVersion, $first->id, hash('sha256', $first->token)));
        PushFixtures::subscriptions($this->pdo)->subscribe($this->member, 'https://push.example/device',
            PushFixtures::key(), PushFixtures::auth(), null, hash('sha256', $last->token), deviceLabel: 'Kitchen tablet');
        self::assertSame('Kitchen tablet', $this->logins->list($this->member, $last->id)[0]['device_label']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        (new UserRepository($this->pdo))->bumpSessionVersion($this->member->id);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions WHERE revoked_at IS NULL')->fetchColumn());
        // Version invalidation retains its previous anonymous/bootstrap behavior, not session_revoked.
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
    }

    public function testIssuanceAndPushRevocationRollBackWithTheirOuterEffects(): void
    {
        $this->pdo->beginTransaction();
        $issued = $this->logins->issue($this->member);
        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        self::assertSame('authentication_required', $this->logins->invalidReason($this->member->id, $this->member->sessionVersion, $issued->id, hash('sha256', $issued->token)));
        $first = $this->signIn();
        PushFixtures::subscriptions($this->pdo)->subscribe($this->member, 'https://push.example/device',
            PushFixtures::key(), PushFixtures::auth(), null, hash('sha256', $first->token));
        $second = $this->signIn();
        $this->pdo->exec("CREATE FUNCTION reject_session_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action = 'auth.sessions_revoked' THEN RAISE EXCEPTION 'audit failed'; END IF; RETURN NEW; END; \$\$");
        $this->pdo->exec('CREATE TRIGGER reject_session_audit BEFORE INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION reject_session_audit()');
        try {
            try {
                $this->logins->revoke($this->member, $second->id, hash('sha256', $second->token), $first->id, '127.0.0.1');
                self::fail('Audit failure did not abort revocation.');
            } catch (\PDOException) {
                self::assertNull($this->logins->invalidReason($this->member->id, $this->member->sessionVersion, $first->id, hash('sha256', $first->token)));
                self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
            }
        } finally {
            $this->pdo->exec('DROP TRIGGER reject_session_audit ON audit_log');
            $this->pdo->exec('DROP FUNCTION reject_session_audit()');
        }
    }

    private function fails(string $code, callable $operation): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (ApiException $exception) { self::assertSame($code, $exception->errorCode); }
    }
}
