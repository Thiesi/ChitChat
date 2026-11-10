<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\PasswordRecoveryKey;
use ChitChat\Auth\PasswordResetService;
use ChitChat\Auth\PrivilegedStepUpService;
use ChitChat\Auth\RecoveryKeyService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\Totp\TotpCeremonyService;
use ChitChat\Auth\Totp\TotpKeyRing;
use ChitChat\Auth\Totp\TotpProtocol;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestSession;
use ChitChat\Tests\Support\TestTotp;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class TotpCeremonyTest extends DatabaseTestCase
{
    private TestSession $session;
    private AuthenticatedUser $root;
    private AuthenticatedUser $member;
    private string $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $this->config = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson(
            json_encode(['fixture' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 'fixture'),
            'webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test']);
        $auth = new AuthService($this->pdo, $this->config);
        $this->root = $auth->register('Root', 'initial strong secret', '127.0.0.1');
        $this->member = $auth->register('Member', 'initial strong secret', '127.0.0.2');
        $this->seed = TestTotp::seed($this->pdo, $this->config, $this->member->id);
        $this->member = $this->fresh();
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    private function fresh(): AuthenticatedUser
    {
        return (new UserRepository($this->pdo))->findAuthenticatedById($this->member->id)
            ?? throw new \RuntimeException('Missing fixture account.');
    }

    private function code(): string
    {
        return TotpProtocol::codeAtStep($this->seed, intdiv(time(), 30));
    }

    public function testLoginUsesExistingPrimaryContextAndIpPinAndCreatesExactlyOneRegisteredLogin(): void
    {
        SessionManager::beginMfaLogin($this->member, '127.0.0.2', 300);
        $pending = $_SESSION;
        $ceremony = new TotpCeremonyService($this->pdo, $this->config);
        $this->rejects('mfa_login_expired', fn () => $ceremony->login($this->code(), '127.0.0.3'));
        self::assertSame(-1, $this->lastStep());
        $_SESSION = $pending;
        $result = $ceremony->login($this->code(), '127.0.0.2');
        self::assertSame($this->member->id, $result['user']['id']);
        self::assertSame('totp', $result['mfa_method']);
        self::assertFalse($result['restored']);
        self::assertCount(1, (new LoginSessionRepository($this->pdo))->list($this->fresh(), SessionManager::loginBinding()['id']));
        self::assertTrue(SessionManager::privilegedStepUpStatus($this->fresh(), $this->config)['active']);
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
        $_SESSION = $pending; // Lost response cannot authorize a second login with the consumed code.
        $this->rejects('invalid_totp', fn () => $ceremony->login($this->code(), '127.0.0.2'));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions')->fetchColumn());
    }

    public function testTotpStepUpEqualsPasskeyEvenWithAPasskeyAndPasswordCannotProvideMfaStepUp(): void
    {
        TestPasskey::create()->seed($this->pdo, $this->member->id);
        $this->member = $this->fresh();
        SessionManager::login($this->member, (new LoginSessionRepository($this->pdo))->issue($this->member));
        $this->rejects('passkey_step_up_required', fn () => (new PrivilegedStepUpService($this->pdo, $this->config))
            ->verify($this->member, 'initial strong secret', '127.0.0.2'));
        $result = (new TotpCeremonyService($this->pdo, $this->config))->stepUp($this->member, $this->code(), '127.0.0.2');
        self::assertTrue($result['security']['privileged_step_up']['active']);
        self::assertSame('totp', $result['security']['privileged_step_up']['method']);
        SessionManager::requirePrivilegedStepUp($this->fresh(), $this->config);
        $this->rejects('invalid_totp', fn () => (new TotpCeremonyService($this->pdo, $this->config))
            ->stepUp($this->fresh(), $this->code(), '127.0.0.2'));
    }

    public function testTotpRestorationRestoresProtectedRolesOnlyAfterTheFactorCommits(): void
    {
        TestPasskey::create()->seed($this->pdo, $this->root->id);
        $this->pdo->exec("INSERT INTO user_roles(user_id,role) VALUES (" . $this->member->id . ",'admin')");
        $this->pdo->exec('UPDATE system_settings SET mfa_required_for_admin_roles = TRUE WHERE id = 1');
        $closure = new AccountClosureService($this->pdo, $this->config);
        $closure->request($this->fresh(), '127.0.0.2');
        $pending = $closure->authenticateRestore('Member', 'initial strong secret', '127.0.0.2');
        SessionManager::beginMfaLogin($pending, '127.0.0.2', 300, 'restore');
        self::assertSame('closure_pending', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $this->member->id)->fetchColumn());
        $result = (new TotpCeremonyService($this->pdo, $this->config))->login($this->code(), '127.0.0.2');
        self::assertTrue($result['restored']);
        self::assertContains('admin', $result['user']['roles']);
        self::assertSame('active', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $this->member->id)->fetchColumn());
        self::assertGreaterThan(-1, $this->lastStep());
    }

    public function testPasswordResetConsumesTheKeyAndTotpTogetherAndGrantsNoElevation(): void
    {
        SessionManager::login($this->member, (new LoginSessionRepository($this->pdo))->issue($this->member));
        SessionManager::establishPrivilegedStepUp($this->member, 'totp');
        $recovery = (new RecoveryKeyService($this->pdo, $this->config))->generate($this->member, '127.0.0.2')['recovery_key'];
        $_SESSION = []; // Independent reset browser.
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0 WHERE id = 1');
        $reset = new PasswordResetService($this->pdo, $this->config);
        $nonce = $reset->challenge('127.0.0.2')['challenge']['nonce'];
        $pending = $reset->begin('Member', $recovery, 'replacement strong secret', $nonce, '0', '127.0.0.2');
        self::assertSame('pending_mfa', $pending['status']);
        $persisted = $_SESSION;
        $result = $reset->finishTotp($this->code(), '127.0.0.2');
        self::assertSame('complete', $result['status']);
        self::assertNotSame($recovery, $result['recovery_key']);
        self::assertTrue(PasswordRecoveryKey::hash($result['recovery_key']) !== null);
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
        self::assertTrue(password_verify('replacement strong secret',
            (string) $this->pdo->query('SELECT password_hash FROM users WHERE id = ' . $this->member->id)->fetchColumn()));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions WHERE revoked_at IS NOT NULL')->fetchColumn());
        $_SESSION = $persisted;
        $this->rejects('reset_not_pending', fn () => $reset->cancel('127.0.0.2'));
        self::assertGreaterThan(-1, $this->lastStep());
    }

    public function testPasswordWriteFailureRollsBackTheTotpFenceAndRecoveryKeySoTheSameProofCanRetry(): void
    {
        SessionManager::login($this->member, (new LoginSessionRepository($this->pdo))->issue($this->member));
        SessionManager::establishPrivilegedStepUp($this->member, 'totp');
        $recovery = (new RecoveryKeyService($this->pdo, $this->config))->generate($this->member, '127.0.0.2')['recovery_key'];
        $_SESSION = [];
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0 WHERE id = 1');
        $reset = new PasswordResetService($this->pdo, $this->config);
        $nonce = $reset->challenge('127.0.0.2')['challenge']['nonce'];
        $reset->begin('Member', $recovery, 'replacement strong secret', $nonce, '0', '127.0.0.2');
        $before = $this->pdo->query("SELECT u.password_hash,u.session_version,recovery.generation,recovery.key_hash FROM users u
            JOIN password_recovery_keys recovery ON recovery.user_id=u.id WHERE u.id=" . $this->member->id)->fetch();
        $proof = $this->code();
        $this->pdo->exec(<<<'SQL'
CREATE FUNCTION test_totp_password_write_failure() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.password_hash IS DISTINCT FROM OLD.password_hash THEN
        RAISE EXCEPTION 'simulated_password_write_failure';
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER test_totp_password_write_failure BEFORE UPDATE OF password_hash ON users
FOR EACH ROW EXECUTE FUNCTION test_totp_password_write_failure();
SQL);
        try {
            try {
                $reset->finishTotp($proof, '127.0.0.2');
                self::fail('Expected password-write failure.');
            } catch (\PDOException $error) {
                self::assertSame('P0001', $error->getCode());
            }
            self::assertSame(-1, $this->lastStep());
            self::assertSame($before, $this->pdo->query("SELECT u.password_hash,u.session_version,recovery.generation,recovery.key_hash FROM users u
                JOIN password_recovery_keys recovery ON recovery.user_id=u.id WHERE u.id=" . $this->member->id)->fetch());
            self::assertArrayHasKey('pending_password_reset', $_SESSION);
            self::assertArrayNotHasKey('auth', $_SESSION);
        } finally {
            $this->pdo->exec('DROP TRIGGER test_totp_password_write_failure ON users');
            $this->pdo->exec('DROP FUNCTION test_totp_password_write_failure()');
        }
        self::assertSame('complete', $reset->finishTotp($proof, '127.0.0.2')['status']);
        self::assertGreaterThan(-1, $this->lastStep());
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
    }

    private function lastStep(): int
    {
        return (int) $this->pdo->query('SELECT last_accepted_step FROM totp_credentials WHERE user_id = ' . $this->member->id)->fetchColumn();
    }

    private function rejects(string $expected, callable $operation): void
    {
        try { $operation(); self::fail('Expected ' . $expected); }
        catch (ApiException $error) { self::assertSame($expected, $error->errorCode); }
    }
}