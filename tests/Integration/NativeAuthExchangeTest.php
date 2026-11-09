<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthExchange;
use ChitChat\Auth\NativeAuth\NativeAuthOutcome;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Auth\NativeAuth\NativeAuthTransactions;
use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeAuthExchangeTest extends DatabaseTestCase
{
    private TestSession $session;
    private Config $nativeConfig;
    private AuthenticatedUser $member;
    private NativeAuthRepository $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $this->member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $this->nativeConfig = $this->configWith(['applicationOrigin' => 'https://chat.test',
            'oidcRedirectOrigin' => 'https://chat.test',
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']],
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat',
                'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]]]);
        $this->rows = new NativeAuthRepository($this->pdo);
        $this->rows->statement("INSERT INTO user_identities (user_id,provider,subject) VALUES (:id,'google','subject-native')",
            ['id' => $this->member->id]);
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    /** Verified staging is a fixture here; real provider verification has its own signed/HTTP coverage.
     * @return array{transaction_id:string,code:string,code_verifier:string,state:string}
     */
    private function staged(bool $unknownIdentity = false): array
    {
        $state = NativeConfig::token();
        $start = (new NativeAuthTransactions($this->pdo, $this->nativeConfig))->start([
            'client_id' => 'de.networkxxiii.chitchat', 'provider' => 'google', 'purpose' => 'login',
            'state' => $state, 'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256'], null, '192.0.2.1');
        $id = $start['transaction_id'];
        $this->rows->stageProof($id, 'consent', $unknownIdentity ? null : $this->member->id,
            $unknownIdentity ? null : $this->member->sessionVersion,
            ['subject' => $unknownIdentity ? 'new-private-subject' : 'subject-native', 'auth_time' => null, 'started_at' => time()], null);
        $code = NativeConfig::token();
        $this->rows->statement("UPDATE native_auth_transactions SET phase = 'staged', code_hash = :hash, code_expires_at = LEAST(clock_timestamp() + interval '90 seconds', expires_at) WHERE id = :id",
            ['hash' => hash('sha256', $code), 'id' => $id]);
        return ['transaction_id' => $id, 'code' => $code,
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'state' => $state];
    }

    public function testWrongStateCodeAndVerifierLeaveTheGrantUsableAndSuccessConsumesExactlyOnce(): void
    {
        $grant = $this->staged();
        $exchange = new NativeAuthExchange($this->pdo, $this->nativeConfig);
        $csrf = SessionManager::csrfToken();
        foreach (['state' => 'state_mismatch', 'code' => 'code_mismatch', 'code_verifier' => 'verifier_mismatch'] as $field => $error) {
            try {
                $exchange->exchange(array_replace($grant, [$field => NativeConfig::token()]), '198.51.100.2');
                self::fail('Bad exchange proof was accepted.');
            } catch (ApiException $exception) {
                self::assertSame($error, $exception->errorCode);
            }
            self::assertSame('staged', $this->rows->require($grant['transaction_id'])['phase']);
            self::assertArrayNotHasKey('auth', $_SESSION);
        }
        $result = $exchange->exchange($grant, '198.51.100.2');
        self::assertSame('completed', $result['outcome']);
        self::assertSame($this->member->id, $result['user']['id']);
        self::assertNotSame($csrf, $result['csrf_token']);
        self::assertSame($this->member->id, $_SESSION['auth']['user_id']);
        foreach (['proof', 'picture', 'code_hash', 'bootstrap_hash', 'return_state'] as $secret) {
            self::assertNull($this->rows->require($grant['transaction_id'])[$secret], $secret);
        }
        $attempts = (int) $this->pdo->query("SELECT COUNT(*) FROM login_attempts WHERE successful")->fetchColumn();
        try {
            $exchange->exchange($grant, '198.51.100.2');
            self::fail('Grant replay was accepted.');
        } catch (ApiException $exception) {
            self::assertSame('already_used', $exception->errorCode);
        }
        self::assertSame($attempts, (int) $this->pdo->query("SELECT COUNT(*) FROM login_attempts WHERE successful")->fetchColumn());
        $audit = (string) $this->pdo->query("SELECT string_agg(metadata_json::text, '') FROM audit_log")->fetchColumn();
        self::assertStringNotContainsString($grant['code'], $audit);
        self::assertStringNotContainsString($grant['code_verifier'], $audit);
        self::assertStringNotContainsString('subject-native', $audit);
    }

    public function testPostCommitSessionFailureDoesNotReissueTheGrantOrRepeatTheDatabaseEffect(): void
    {
        $grant = $this->staged();
        $exchange = new NativeAuthExchange($this->pdo, $this->nativeConfig,
            static function (NativeAuthOutcome $plan, string $ip): array {
                throw new \RuntimeException('Session persistence failure fixture.');
            });
        try {
            $exchange->exchange($grant, '192.0.2.1');
            self::fail('Expected session failure fixture.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Session persistence failure fixture.', $exception->getMessage());
        }
        self::assertSame('consumed', $this->rows->require($grant['transaction_id'])['phase']);
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
        try {
            (new NativeAuthExchange($this->pdo, $this->nativeConfig))->exchange($grant, '192.0.2.1');
            self::fail('A committed grant was rebound after session failure.');
        } catch (ApiException $exception) {
            self::assertSame('already_used', $exception->errorCode);
        }
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
    }

    public function testUnlinkedProviderOnlyTransfersPendingSignupAndCreatesNoAccount(): void
    {
        $grant = $this->staged(true);
        $result = (new NativeAuthExchange($this->pdo, $this->nativeConfig))->exchange($grant, '192.0.2.1');
        self::assertSame('pending_sign_up', $result['outcome']);
        self::assertNull($result['user']);
        self::assertSame('google', $result['pending_sign_up']['id']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertSame($grant['transaction_id'], $_SESSION['oidc_sign_up']['native_transaction']);
        self::assertNull($this->rows->require($grant['transaction_id'])['proof']);
    }
}
