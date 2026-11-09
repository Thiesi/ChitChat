<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthContinuation;
use ChitChat\Auth\NativeAuth\NativeAuthExchange;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Auth\NativeAuth\NativeAuthTransactions;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Policy\PolicyService;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeAuthContinuationTest extends DatabaseTestCase
{
    private TestSession $session;
    private Config $nativeConfig;
    private AuthenticatedUser $root;
    private AuthenticatedUser $member;
    private NativeAuthRepository $rows;
    private NativeAuthContinuation $continuation;
    private TestPasskey $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $this->root = $auth->register('Root', 'a very secure password', '192.0.2.1');
        $this->member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $this->key = TestPasskey::create();
        $this->key->seed($this->pdo, $this->member->id);
        $this->nativeConfig = $this->configWith(['applicationOrigin' => 'https://chat.test', 'oidcRedirectOrigin' => 'https://chat.test',
            'webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test',
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']],
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]]]);
        $this->rows = new NativeAuthRepository($this->pdo);
        $this->continuation = new NativeAuthContinuation($this->pdo, $this->nativeConfig);
        $this->rows->statement("INSERT INTO user_identities(user_id,provider,subject) VALUES (:id,'google','private-subject')", ['id' => $this->member->id]);
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    /** Browser verification is exercised separately; this fixture seeds only the verified consent result.
     * @return array{id:string,result:array<string,mixed>}
     */
    private function pending(string $purpose = 'login', bool $unknown = false): array
    {
        $state = NativeConfig::token();
        $start = (new NativeAuthTransactions($this->pdo, $this->nativeConfig))->start([
            'client_id' => 'de.networkxxiii.chitchat', 'provider' => 'google', 'purpose' => $purpose,
            'state' => $state, 'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256'], null, '192.0.2.1');
        $id = $start['transaction_id'];
        $user = $purpose === 'restore' ? (new UserRepository($this->pdo))->findClosurePendingById($this->member->id) : $this->member;
        $this->rows->stageProof($id, 'consent', $unknown ? null : $user->id, $unknown ? null : $user->sessionVersion,
            ['subject' => $unknown ? 'new-private-subject' : 'private-subject', 'auth_time' => null, 'started_at' => time()], null);
        $code = NativeConfig::token();
        $this->rows->statement("UPDATE native_auth_transactions SET phase = 'staged', code_hash = :hash, code_expires_at = LEAST(clock_timestamp() + interval '90 seconds', expires_at) WHERE id = :id",
            ['id' => $id, 'hash' => hash('sha256', $code)]);
        $result = (new NativeAuthExchange($this->pdo, $this->nativeConfig))->exchange(['transaction_id' => $id,
            'code' => $code, 'state' => $state, 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'], '192.0.2.1');
        return ['id' => $id, 'result' => $result];
    }

    public function testNativeMfaFollowsTheSessionAcrossIpChangesAndCompletesOnlyOnce(): void
    {
        $pending = $this->pending();
        self::assertSame('pending_mfa', $pending['result']['outcome']);
        self::assertArrayNotHasKey('auth', $_SESSION);
        $options = $this->continuation->mfaOptions('198.51.100.5')['public_key'];
        $result = $this->continuation->finishMfa($this->key->assertion($options, 1), 'passkey', '203.0.113.6');
        self::assertSame('completed', $result['outcome']);
        self::assertSame($this->member->id, $result['user']['id']);
        self::assertSame('passkey', $result['mfa_method']);
        self::assertTrue($result['security']['privileged_step_up']['active']);
        self::assertSame(1, (int) $this->pdo->query('SELECT sign_count FROM webauthn_credentials')->fetchColumn());
        self::assertSame('completed', $this->rows->require($pending['id'])['outcome']);
        self::assertSame(0, (int) $this->rows->require($pending['id'])['active']);
        $this->rejects('mfa_login_expired', fn () => $this->continuation->finishMfa($this->key->recoveryCode, 'recovery_code', '203.0.113.7'));
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($this->member->id));
    }

    public function testRevokedTrustAndChangedIdentityCannotConsumeARecoveryCodeOrSignIn(): void
    {
        $pending = $this->pending();
        $badConfig = $this->configWith(['nativeAndroidApps' => []]);
        $this->rejects('native_config_changed', fn () => (new NativeAuthContinuation($this->pdo, $badConfig))->finishMfa($this->key->recoveryCode, 'recovery_code', '203.0.113.2'));
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($this->member->id));
        $this->pdo->exec('DELETE FROM user_identities');
        $this->rejects('identity_changed', fn () => $this->continuation->finishMfa($this->key->recoveryCode, 'recovery_code', '203.0.113.2'));
        self::assertSame(1, (new MfaRepository($this->pdo))->recoveryCodesRemaining($this->member->id));
        self::assertSame('pending_mfa', $this->rows->require($pending['id'])['outcome']);
        self::assertArrayNotHasKey('auth', $_SESSION);
        $this->continuation->reconcileSession();
        self::assertFalse(NativeAuthContinuation::pending());
    }

    public function testMfaRestorationLeavesTheClosureUntilTheSecondFactorCommits(): void
    {
        (new AccountClosureService($this->pdo, $this->config))->request($this->member, '192.0.2.1');
        $pending = $this->pending('restore');
        self::assertSame('pending_mfa', $pending['result']['outcome']);
        self::assertSame('closure_pending', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $this->member->id)->fetchColumn());
        $this->rejects('invalid_recovery_code', fn () => $this->continuation->finishMfa('wrong', 'recovery_code', '203.0.113.3'));
        self::assertSame('closure_pending', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $this->member->id)->fetchColumn());
        $result = $this->continuation->finishMfa($this->key->recoveryCode, 'recovery_code', '203.0.113.4');
        self::assertTrue($result['restored']);
        self::assertSame(0, $result['recovery_codes_remaining']);
        self::assertSame('active', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $this->member->id)->fetchColumn());
        self::assertSame('completed', $this->rows->require($pending['id'])['outcome']);
    }

    public function testSignUpKeepsRulesAcceptanceAndAccountCreationInsideItsOneUseTransaction(): void
    {
        $pending = $this->pending(unknown: true);
        self::assertSame('pending_sign_up', $pending['result']['outcome']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->rejects('native_flow_required', fn () => (new OidcService($this->pdo, $this->nativeConfig))->completeSignUp('NewMember', null, '192.0.2.3'));
        (new PolicyService($this->pdo, $this->nativeConfig))->update($this->root, 'rules', 'hosted', 'Be kind.', null, true, 'First publish', '192.0.2.1');
        $this->rejects('rules_acceptance_required', fn () => $this->continuation->finishSignUp('NewMember', null, null, '192.0.2.3'));
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame('pending_sign_up', $this->rows->require($pending['id'])['outcome']);
        $result = $this->continuation->finishSignUp('NewMember', null, 1, '198.51.100.3');
        self::assertSame('completed', $result['outcome']);
        self::assertSame('sign_up', $result['purpose']);
        self::assertSame('NewMember', $result['user']['username']);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM rules_acceptances')->fetchColumn());
        self::assertSame('completed', $this->rows->require($pending['id'])['outcome']);
        $this->rejects('native_session_changed', fn () => $this->continuation->finishSignUp('AnotherName', null, 1, '192.0.2.3'));
    }

    public function testNewPrimaryIntentClearsTheOldNativeContinuationAndOrdinaryWebMfaKeepsItsIpPin(): void
    {
        $first = $this->pending(unknown: true);
        $this->pending();
        self::assertSame('superseded', $this->rows->require($first['id'])['phase']);
        SessionManager::beginMfaLogin($this->member, '192.0.2.1', 300);
        self::assertFalse(NativeAuthContinuation::pending());
        SessionManager::assertPendingMfaTransport('192.0.2.1');
        $this->rejects('mfa_login_expired', fn () => SessionManager::assertPendingMfaTransport('198.51.100.1'));
        self::assertArrayNotHasKey('pending_mfa', $_SESSION);
    }

    private function rejects(string $code, callable $operation): void
    {
        try { $operation(); self::fail('Invalid native continuation was accepted.'); }
        catch (ApiException $exception) { self::assertSame($code, $exception->errorCode); }
    }
}