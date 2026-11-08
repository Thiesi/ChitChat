<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\PasswordRecoveryKey;
use ChitChat\Auth\PasswordResetService;
use ChitChat\Auth\RecoveryKeyService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationService;
use ChitChat\Tests\Support\TestPasskey;
use PDOException;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class PasswordResetServiceTest extends DatabaseTestCase
{
    private \ChitChat\Tests\Support\TestSession $session;
    private const IP = '127.0.0.8';
    private const OLD = 'initial strong secret';
    private const NEW_PASSWORD = 'replacement strong secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new \ChitChat\Tests\Support\TestSession();
        $this->session->start();
        $_SESSION = [];
        $this->config = $this->configWith(['webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test']);
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0 WHERE id = 1');
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    public function testRegistrationStoresOnlyHashAndPrivateMetadataAndResetRotatesAllProofs(): void
    {
        [$user, $key] = $this->account();
        $before = $this->row($user->id);
        self::assertSame(PasswordRecoveryKey::hash($key), $before['key_hash']);
        self::assertNotSame($key, $before['key_hash']);
        self::assertSame(['exists' => true, 'created_at' => RecoveryKeyService::timestamp($before['created_at'])],
            $user->toSessionArray()['recovery_key']);
        self::assertArrayNotHasKey('recovery_key', $user->toArray());
        SessionManager::login($user);
        $oldSession = $_SESSION;
        $_SESSION = [];
        $result = $this->begin($user->username, strtolower(str_replace('-', ' ', $key)));
        self::assertSame('complete', $result['status']);
        self::assertNotSame($key, $result['recovery_key']);
        self::assertTrue($result['user']['recovery_key']['exists']);
        $after = $this->row($user->id);
        self::assertSame($user->sessionVersion + 1, (int) $after['session_version']);
        self::assertTrue(password_verify(self::NEW_PASSWORD, $after['password_hash']));
        self::assertFalse(password_verify(self::OLD, $after['password_hash']));
        self::assertSame(PasswordRecoveryKey::hash($result['recovery_key']), $after['key_hash']);
        self::assertNotSame($before['generation'], $after['generation']);
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
        self::assertArrayNotHasKey('pending_password_reset', $_SESSION);
        self::assertSame($user->id, SessionManager::currentUser(new UserRepository($this->pdo))?->id);
        $_SESSION = $oldSession;
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
        $this->assertError('reset_failed', fn () => $this->begin($user->username, $key), 400);
        $audit = (string) $this->pdo->query('SELECT json_agg(audit_log)::text FROM audit_log')->fetchColumn();
        foreach ([$key, $result['recovery_key'], self::NEW_PASSWORD, $before['key_hash'], $after['key_hash']] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
    }

    public function testNegativeProofsAreGenericNonceIsOneUseAndWeakPasswordIsExplicit(): void
    {
        [$user, $key] = $this->account();
        $service = new PasswordResetService($this->pdo, $this->config);
        $nonce = $service->challenge(self::IP)['challenge']['nonce'];
        $first = $this->assertError('reset_failed', fn () => $service->begin('Unknown', $key, self::NEW_PASSWORD, $nonce, '0', self::IP), 400);
        $second = $this->assertError('reset_failed', fn () => $service->begin($user->username, $key, self::NEW_PASSWORD, $nonce, '0', self::IP), 400);
        self::assertSame($first->getMessage(), $second->getMessage());
        $wrong = $this->assertError('reset_failed', fn () => $this->begin($user->username, PasswordRecoveryKey::generate()), 400);
        self::assertSame($first->getMessage(), $wrong->getMessage());
        $this->assertError('validation_error', fn () => $this->begin($user->username, $key, 'short'), 400);
        $this->pdo->exec("DELETE FROM password_recovery_keys WHERE user_id = {$user->id}");
        $missing = $this->assertError('reset_failed', fn () => $this->begin($user->username, $key), 400);
        self::assertSame($first->getMessage(), $missing->getMessage());
        self::assertSame(['exists' => false, 'created_at' => null],
            (new UserRepository($this->pdo))->findAuthenticatedById($user->id)?->toSessionArray()['recovery_key']);
        $provider = (new AuthService($this->pdo, $this->config))->registerWithProvider('Provider', 'google', 'subject', '127.0.0.9');
        self::assertNull($provider->toSessionArray()['recovery_key']);
        self::assertSame($user->sessionVersion, (int) $this->row($user->id)['session_version']);
    }

    public function testMfaRecoveryIsSeparateStagedAndAtomicAndDoesNotGrantElevation(): void
    {
        [$user, $key] = $this->account();
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $user->id);
        $before = $this->row($user->id);
        $pending = $this->begin($user->username, $key);
        self::assertSame('pending_mfa', $pending['status']);
        self::assertTrue($pending['mfa_required']);
        self::assertNull(SessionManager::currentUser(new UserRepository($this->pdo)));
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
        self::assertSame($before, $this->row($user->id));
        $state = json_encode($_SESSION, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($key, $state);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $state);
        $service = new PasswordResetService($this->pdo, $this->config);
        $this->assertError('invalid_recovery_code', fn () => $service->finishRecovery($key, self::IP), 403);
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        $result = $service->finishRecovery($passkey->recoveryCode, self::IP);
        self::assertSame('complete', $result['status']);
        self::assertNotSame($key, $result['recovery_key']);
        self::assertSame(0, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        self::assertTrue((new MfaService($this->pdo, $this->config))->requiresMfaForLogin($user));
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
        self::assertSame($user->sessionVersion + 1, (int) $this->row($user->id)['session_version']);
        $this->assertError('reset_not_pending', fn () => $service->cancel(self::IP), 409);
        $_SESSION = [];
        $this->assertError('reset_failed', fn () => $this->begin($user->username, $passkey->recoveryCode), 400);
    }

    public function testRecoveryCodeAndPasskeyUpdatesRollBackWhenPasswordWriteFails(): void
    {
        [$user, $key] = $this->account();
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $user->id);
        $before = $this->row($user->id);
        $this->begin($user->username, $key);
        $service = new PasswordResetService($this->pdo, $this->config);
        $this->pdo->exec(<<<'SQL'
CREATE FUNCTION test_reject_reset() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'deliberate reset rollback'; END $$;
CREATE TRIGGER test_reject_reset BEFORE UPDATE OF password_hash ON users
FOR EACH ROW EXECUTE FUNCTION test_reject_reset();
SQL);
        try {
            try {
                $service->finishRecovery($passkey->recoveryCode, self::IP);
                self::fail('Expected password write failure.');
            } catch (PDOException $exception) {
                self::assertStringContainsString('deliberate reset rollback', $exception->getMessage());
            }
            self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
            self::assertSame($before, $this->row($user->id));
            self::assertArrayHasKey('pending_password_reset', $_SESSION);
            $options = $service->options(self::IP);
            try {
                $service->finishAssertion($passkey->assertion($options), self::IP);
                self::fail('Expected passkey completion to roll back.');
            } catch (PDOException $exception) {
                self::assertStringContainsString('deliberate reset rollback', $exception->getMessage());
            }
            self::assertSame(0, (int) $this->pdo->query("SELECT sign_count FROM webauthn_credentials WHERE user_id = {$user->id}")->fetchColumn());
            self::assertSame($before, $this->row($user->id));
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('auth.password_reset_succeeded', 'auth.password_reset_mfa_succeeded')")->fetchColumn());
            self::assertGreaterThan(0, (int) $this->pdo->query("SELECT COUNT(*) FROM request_rate_limits WHERE scope IN ('mfa_assertion', 'mfa_recovery')")->fetchColumn());
        } finally {
            $this->pdo->exec('DROP TRIGGER test_reject_reset ON users; DROP FUNCTION test_reject_reset()');
        }
        self::assertSame('complete', $service->finishAssertion($passkey->assertion($service->options(self::IP)), self::IP)['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT sign_count FROM webauthn_credentials WHERE user_id = {$user->id}")->fetchColumn());
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
    }

    public function testCancellationExpiryIpChangeAndReplacementInvalidatePendingWithoutBurningMfa(): void
    {
        [$user, $key] = $this->account();
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $user->id);
        $before = $this->row($user->id);
        $service = new PasswordResetService($this->pdo, $this->config);
        $this->begin($user->username, $key);
        self::assertSame('cancelled', $service->cancel(self::IP)['status']);
        self::assertSame($before, $this->row($user->id));
        $this->assertError('reset_not_pending', fn () => $service->cancel(self::IP), 409);
        $this->begin($user->username, $key);
        $_SESSION['pending_password_reset']['expires_at'] = time() - 1;
        $this->assertError('reset_failed', fn () => $service->finishRecovery($passkey->recoveryCode, self::IP), 400);
        $this->begin($user->username, $key);
        $this->assertError('reset_failed', fn () => $service->finishRecovery($passkey->recoveryCode, '127.0.0.99'), 400);
        $this->begin($user->username, $key);
        $pendingSession = $_SESSION;
        $_SESSION = [];
        SessionManager::login($user);
        SessionManager::establishPrivilegedStepUp($user, 'passkey');
        $replacement = (new RecoveryKeyService($this->pdo, $this->config))->generate($user, self::IP);
        $_SESSION = $pendingSession;
        $this->assertError('reset_failed', fn () => $service->finishRecovery($passkey->recoveryCode, self::IP), 400);
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        self::assertSame($before['password_hash'], $this->row($user->id)['password_hash']);
        self::assertSame(PasswordRecoveryKey::hash($replacement['recovery_key']), $this->row($user->id)['key_hash']);
    }

    public function testOrdinaryPasswordChangeKeepsKeyButAdminResetInvalidatesItAndStaleActorsFail(): void
    {
        [$root] = $this->account('Root');
        [$user, $key] = $this->account('Member');
        $auth = new AuthService($this->pdo, $this->config);
        $other = Database::connect($this->config);
        $other->beginTransaction();
        $other->query("SELECT id FROM users WHERE id = {$user->id} FOR KEY SHARE");
        $this->pdo->exec("SET lock_timeout = '1s'");
        try {
            $changed = $auth->changePassword($user, self::OLD, self::NEW_PASSWORD, self::IP);
        } finally {
            $other->rollBack();
            $this->pdo->exec('RESET lock_timeout');
        }
        self::assertSame(PasswordRecoveryKey::hash($key), $this->row($user->id)['key_hash']);
        self::assertSame($user->sessionVersion + 1, $changed->sessionVersion);
        $this->assertError('authentication_required', fn () => $auth->changePassword($user, self::NEW_PASSWORD, 'stale overwritten secret', self::IP), 401);
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $user->id);
        $this->begin($user->username, $key);
        (new ModerationService($this->pdo))->resetPassword($root, $user->id, 'administrator new secret', self::IP);
        $this->assertError('reset_failed', fn () => (new PasswordResetService($this->pdo, $this->config))->finishRecovery($passkey->recoveryCode, self::IP), 400);
        self::assertNull($this->row($user->id)['key_hash']);
        self::assertTrue(password_verify('administrator new secret', $this->row($user->id)['password_hash']));
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
    }

    public function testTwoStagedResetsHaveOnlyOneWinnerAndCrossPurposePasskeysCannotComplete(): void
    {
        [$user, $key] = $this->account();
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $user->id);
        $service = new PasswordResetService($this->pdo, $this->config);
        $this->begin($user->username, $key);
        $first = $_SESSION;
        $_SESSION = [];
        $this->begin($user->username, $key, 'second replacement secret');
        $second = $_SESSION;
        $_SESSION = $first;
        $wrongOptions = (new MfaService($this->pdo, $this->config))->beginAssertion($user, 'mfa_login', self::IP);
        $this->assertError('webauthn_challenge_expired', fn () => $service->finishAssertion($passkey->assertion($wrongOptions), self::IP), 403);
        $options = $service->options(self::IP);
        self::assertSame('complete', $service->finishAssertion($passkey->assertion($options), self::IP)['status']);
        $_SESSION = $second;
        $this->assertError('reset_failed', fn () => $service->finishRecovery($passkey->recoveryCode, self::IP), 400);
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        self::assertTrue(password_verify(self::NEW_PASSWORD, $this->row($user->id)['password_hash']));
    }

    public function testLockdownAndRegistrationDisableMatchLoginAndClosureDestroysKey(): void
    {
        [$root, $rootKey] = $this->account('Root');
        [$member, $memberKey] = $this->account('Member');
        $this->pdo->exec("UPDATE system_settings SET registration_enabled = FALSE, lockdown_enabled = TRUE, lockdown_message = 'Maintenance' WHERE id = 1");
        $this->assertError('maintenance_lockdown', fn () => $this->begin($member->username, $memberKey), 503);
        self::assertSame('complete', $this->begin($root->username, $rootKey)['status']);
        $_SESSION = [];
        $this->pdo->exec('UPDATE system_settings SET lockdown_enabled = FALSE WHERE id = 1');
        self::assertSame('complete', $this->begin($member->username, $memberKey)['status']);
        $this->pdo->exec("UPDATE users SET account_state = 'closed', closure_requested_at = NOW() - INTERVAL '2 days', closure_finalizes_at = NOW() - INTERVAL '1 day', closed_at = NOW() WHERE id = {$member->id}");
        self::assertNull($this->row($member->id)['key_hash']);
    }

    public function testIndependentBudgetsPersistAndConsumeNonceEvenWhenThrottled(): void
    {
        [$user, $key] = $this->account();
        $config = $this->configWith(['rateLimits' => $this->config->rateLimits->with('password_reset', 1, 60)]);
        $service = new PasswordResetService($this->pdo, $config);
        $nonce = $service->challenge(self::IP)['challenge']['nonce'];
        $this->assertError('reset_failed', fn () => $service->begin($user->username, 'invalid', self::NEW_PASSWORD, $nonce, '0', self::IP), 400);
        $nonce = $service->challenge(self::IP)['challenge']['nonce'];
        $error = $this->assertError('rate_limited', fn () => $service->begin($user->username, $key, self::NEW_PASSWORD, $nonce, '0', self::IP), 429);
        self::assertGreaterThan(0, $error->retryAfterSeconds);
        self::assertArrayNotHasKey('password_reset_challenge', $_SESSION);
        self::assertSame($user->id, (new AuthService($this->pdo, $config))->authenticatePassword($user->username, self::OLD, self::IP)->id);
    }

    public function testRulesDoNotGateRecoveryExportsExcludeProofsAndBansAreRechecked(): void
    {
        [$root] = $this->account('Root');
        [$user, $key] = $this->account('Member');
        $policies = new \ChitChat\Policy\PolicyService($this->pdo, $this->config);
        $policies->update($root, 'rules', 'hosted', '# New rules', null, true, 'Material publication', self::IP);
        $result = $this->begin($user->username, $key);
        self::assertSame('complete', $result['status']);
        self::assertTrue($result['user']['rules']['acceptance_required']);
        self::assertSame([], $policies->acceptanceHistory($user->id));
        $fresh = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($fresh);
        $export = (new \ChitChat\Account\PersonalDataExportService($this->pdo, $this->config))->export($fresh, self::IP);
        $json = json_encode($export, JSON_THROW_ON_ERROR);
        $row = $this->row($user->id);
        foreach ([$key, $result['recovery_key'], $row['key_hash'], $row['generation'], $row['password_hash'], self::NEW_PASSWORD] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $_SESSION = [];
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $user->id);
        $this->begin($user->username, $result['recovery_key']);
        (new ModerationService($this->pdo))->ban($root, $user->id, 'Test ban', null, self::IP);
        $this->assertError('reset_failed', fn () => (new PasswordResetService($this->pdo, $this->config))->finishRecovery($passkey->recoveryCode, self::IP), 400);
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($user->id));
        self::assertSame($row['password_hash'], $this->row($user->id)['password_hash']);
        $this->assertError('reset_failed', fn () => $this->begin($user->username, $result['recovery_key']), 400);
    }

    public function testIpBudgetCannotBeBypassedByChangingUsernamesAndManagementRequiresStepUp(): void
    {
        [$user, $key] = $this->account();
        SessionManager::login($user);
        $this->assertError('step_up_required',
            fn () => (new RecoveryKeyService($this->pdo, $this->config))->generate($user, self::IP), 403);
        self::assertSame(PasswordRecoveryKey::hash($key), $this->row($user->id)['key_hash']);
        $_SESSION = [];
        $config = $this->configWith(['rateLimits' => $this->config->rateLimits->with('password_reset_ip', 1, 60)]);
        $service = new PasswordResetService($this->pdo, $config);
        $nonce = $service->challenge(self::IP)['challenge']['nonce'];
        $this->assertError('reset_failed', fn () => $service->begin('UnknownOne', $key, self::NEW_PASSWORD, $nonce, '0', self::IP), 400);
        $nonce = $service->challenge(self::IP)['challenge']['nonce'];
        $this->assertError('rate_limited', fn () => $service->begin('UnknownTwo', $key, self::NEW_PASSWORD, $nonce, '0', self::IP), 429);
        self::assertArrayNotHasKey('password_reset_challenge', $_SESSION);
    }
    /** @return array{AuthenticatedUser,string} */
    private function account(string $username = 'Root'): array
    {
        $result = (new AuthService($this->pdo, $this->config))->registerWithRecoveryKey($username, self::OLD, self::IP);
        return [$result['user'], $result['recovery_key']];
    }

    /** @return array<string,mixed> */
    private function begin(string $username, string $key, string $password = self::NEW_PASSWORD): array
    {
        $service = new PasswordResetService($this->pdo, $this->config);
        $nonce = $service->challenge(self::IP)['challenge']['nonce'];
        return $service->begin($username, $key, $password, $nonce, '0', self::IP);
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $row = $this->pdo->query("SELECT u.password_hash, u.session_version, k.key_hash, k.generation, k.created_at FROM users u LEFT JOIN password_recovery_keys k ON k.user_id = u.id WHERE u.id = $id")->fetch();
        self::assertIsArray($row);
        return $row;
    }

    private function assertError(string $code, callable $operation, int $status): ApiException
    {
        try {
            $operation();
        } catch (ApiException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->status);
            return $exception;
        }
        self::fail('Expected ' . $code);
    }
}
