<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthBootstrap;
use ChitChat\Auth\NativeAuth\NativeAuthBrowser;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthExchange;
use ChitChat\Auth\NativeAuth\NativeAuthProviderFlow;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Auth\NativeAuth\NativeAuthTransactions;
use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\NativeOidcProvider;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeAuthBrowserTest extends DatabaseTestCase
{
    private TestSession $session;
    private Config $nativeConfig;
    private NativeAuthBrowser $browser;
    private NativeAuthProviderFlow $flow;
    private NativeOidcProvider $provider;
    private NativeAuthRepository $rows;
    private AuthenticatedUser $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $this->member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $this->nativeConfig = $this->configWith(['applicationOrigin' => 'https://chat.test', 'oidcRedirectOrigin' => 'https://chat.test',
            'oidcProviders' => ['google' => ['client_id' => NativeOidcProvider::CLIENT_ID, 'client_secret' => NativeOidcProvider::CLIENT_SECRET,
                'endpoint_override' => NativeOidcProvider::ISSUER]],
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]]]);
        $this->provider = new NativeOidcProvider();
        $this->provider->beforeRequest = function (): void { self::assertFalse($this->pdo->inTransaction(), 'Provider HTTP must not hold database locks.'); };
        $this->browser = new NativeAuthBrowser($this->pdo, $this->nativeConfig, $this->provider);
        $this->flow = new NativeAuthProviderFlow($this->pdo, $this->nativeConfig, $this->provider);
        $this->rows = new NativeAuthRepository($this->pdo);
        $this->rows->statement("INSERT INTO user_identities(user_id,provider,subject) VALUES (:id,'google','subject')", ['id' => $this->member->id]);
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    /** @return array{id:string,entry:string,state:string,native:array<string,mixed>} */
    private function start(?AuthenticatedUser $actor = null, string $purpose = 'login'): array
    {
        if ($actor !== null) SessionManager::login($actor);
        $state = NativeConfig::token();
        $start = (new NativeAuthTransactions($this->pdo, $this->nativeConfig))->start([
            'client_id' => 'de.networkxxiii.chitchat', 'provider' => 'google', 'purpose' => $purpose,
            'state' => $state, 'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256'], $actor, '192.0.2.1');
        parse_str((string) parse_url($start['browser_url'], PHP_URL_QUERY), $query);
        $native = $_SESSION;
        $_SESSION = [];
        return ['id' => $start['transaction_id'], 'entry' => $query['entry'], 'state' => $state, 'native' => $native];
    }

    /** @param array{id:string,entry:string,state:string,native:array<string,mixed>} $start
     * @return array{state:string,code:string}
     */
    private function enter(array $start): array
    {
        $this->browser->enterWithSession($start['id'], $start['entry']);
        self::assertSame('entry', $this->browser->view()['phase']);
        self::assertSame(0, $this->provider->exchanges);
        return $this->authorization((string) $this->browser->continueAction('192.0.2.9'));
    }

    /** @return array{state:string,code:string} */
    private function authorization(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->provider->nonce = $query['nonce'];
        $this->provider->challenge = $query['code_challenge'];
        return ['state' => $query['state'], 'code' => 'private-provider-code'];
    }

    public function testDirectCallbackStagesOnlyAndApprovalIssuesOneBoundReturnBeforeNativeExchange(): void
    {
        $start = $this->start();
        $query = $this->enter($start);
        $this->flow->direct($query, '198.51.100.1');
        self::assertSame(1, $this->provider->exchanges);
        self::assertArrayNotHasKey('auth', $_SESSION);
        self::assertSame('consent', $this->browser->view()['phase']);
        self::assertNull($this->browser->view()['return_url']);
        self::assertSame('google', $this->rows->require($start['id'])['provider']);
        $this->browser->approve('198.51.100.1');
        $view = $this->browser->view();
        self::assertSame('staged', $view['phase']);
        self::assertSame('login', $view['purpose']);
        self::assertStringStartsWith('de.networkxxiii.chitchat:/native-auth/callback?', $view['return_url']);
        $this->browser->approve('198.51.100.1');
        self::assertSame($view['return_url'], $this->browser->view()['return_url']);
        parse_str((string) parse_url($view['return_url'], PHP_URL_QUERY), $grant);
        self::assertSame($start['state'], $grant['state']);
        $browserSession = $_SESSION;
        $_SESSION = $start['native'];
        $result = (new NativeAuthExchange($this->pdo, $this->nativeConfig))->exchange([
            ...$grant, 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'], '203.0.113.1');
        self::assertSame('completed', $result['outcome']);
        self::assertSame($this->member->id, $result['user']['id']);
        $_SESSION = $browserSession;
        self::assertSame('used', $this->browser->view()['phase']);
        self::assertSame('unknown', $this->browser->view()['outcome']);
        self::assertNull($this->browser->view()['return_url']);
    }

    public function testStrictCallbackParksOnlyBoundedFieldsOnceAndNeedsTheOriginalBrowserToResume(): void
    {
        $start = $this->start();
        $query = $this->enter($start);
        $browserSession = $_SESSION;
        $_SESSION = [];
        $park = $this->flow->park([...$query, 'id_token' => 'never-store', 'access_token' => 'never-store',
            'code_verifier' => 'never-store', 'error_description' => 'bounded'], '198.51.100.1');
        self::assertSame([], $_SESSION);
        self::assertSame(0, $this->provider->exchanges);
        self::assertLessThanOrEqual(300, $park['remaining']);
        $row = $this->rows->require($start['id']);
        self::assertSame(['code' => 'private-provider-code', 'error_description' => 'bounded'], json_decode($row['callback_query'], true));
        self::assertStringNotContainsString($query['state'], $row['callback_query']);
        $this->rejects('already_used', fn () => $this->flow->park($query, '198.51.100.1'));
        $view = $this->browser->bootstrapView($park['cookie']);
        self::assertSame('verifying', $view['phase']);
        self::assertSame('/api/v1/native-auth/browser/resume.php', $view['actions']['continue']);
        $this->rejects('bootstrap_kind_mismatch', fn () => $this->browser->validateBootstrap($park['cookie'], $view['csrf_token'], 'entry'));
        $this->rejects('csrf_failed', fn () => $this->browser->validateBootstrap($park['cookie'], '', 'callback'));
        $proof = $this->browser->validateBootstrap($park['cookie'], $view['csrf_token'], 'callback');
        $this->rejects('browser_session_changed', fn () => $this->flow->resume($proof, '198.51.100.1'));
        self::assertSame($row['callback_query'], $this->rows->require($start['id'])['callback_query']);
        $_SESSION = $browserSession;
        $this->flow->resume($proof, '203.0.113.2');
        self::assertSame('consent', $this->browser->view()['phase']);
        foreach (['callback_query', 'callback_expires_at', 'bootstrap_hash', 'bootstrap_kind', 'bootstrap_expires_at', 'provider_state_hash'] as $field) self::assertNull($this->rows->require($start['id'])[$field], $field);
        self::assertSame(1, $this->provider->exchanges);
        $this->rejects('bootstrap_mismatch', fn () => $this->flow->resume($proof, '203.0.113.2'));
    }

    public function testEntryBootstrapIsOneUseDetectsAccountMismatchWithoutRotatingTheBrowserSid(): void
    {
        $start = $this->start($this->member, 'picture');
        $bootstrap = $this->browser->prepareEntryBootstrap($start['id'], $start['entry']);
        $this->rejects('entry_mismatch', fn () => $this->browser->prepareEntryBootstrap($start['id'], $start['entry']));
        $view = $this->browser->bootstrapView($bootstrap['cookie']);
        $this->rejects('csrf_failed', fn () => $this->browser->validateBootstrap($bootstrap['cookie'], 'wrong', 'entry'));
        $other = (new AuthService($this->pdo, $this->config))->register('Other', 'a different secure password', '192.0.2.3');
        SessionManager::login($other);
        $sid = session_id();
        $proof = $this->browser->validateBootstrap($bootstrap['cookie'], $view['csrf_token'], 'entry');
        self::assertNull($this->browser->continueBootstrap($proof, '198.51.100.1'));
        self::assertSame($sid, session_id());
        self::assertSame($other->id, $_SESSION['auth']['user_id']);
        self::assertSame('account_mismatch', $this->browser->view()['phase']);
        self::assertSame($this->member->id, $this->browser->view()['bound_account']['id']);
        self::assertSame('Other', $this->browser->view()['browser_account']['username']);
        $this->rejects('bootstrap_mismatch', fn () => $this->browser->continueBootstrap($proof, '198.51.100.1'));
        $this->browser->restart('198.51.100.1');
        self::assertSame('entry', $this->browser->view()['phase']);
        self::assertSame($sid, session_id());
    }

    public function testExpiredParkCannotResumeAndCleanupScrubsItsExactPrivateFields(): void
    {
        $start = $this->start();
        $query = $this->enter($start);
        $park = $this->flow->park($query, '198.51.100.1');
        $view = $this->browser->bootstrapView($park['cookie']);
        $this->rows->statement("UPDATE native_auth_transactions SET callback_expires_at = clock_timestamp() - interval '1 second', bootstrap_expires_at = clock_timestamp() - interval '1 second' WHERE id = :id", ['id' => $start['id']]);
        $this->rejects('expired', fn () => $this->browser->validateBootstrap($park['cookie'], $view['csrf_token'], 'callback'));
        self::assertSame(0, $this->provider->exchanges);
        self::assertSame(1, $this->rows->cleanup(false)['native_auth_expired']);
        foreach (['callback_query', 'callback_expires_at', 'bootstrap_hash', 'provider_state_hash', 'proof'] as $field) self::assertNull($this->rows->require($start['id'])[$field]);
    }

    public function testWrongIdentityAndCancelledProviderNeverApplyLinkOrElevationAndCanRestart(): void
    {
        $start = $this->start($this->member, 'picture');
        $query = $this->enter($start);
        $this->provider->subject = 'unconnected';
        $this->flow->direct($query, '198.51.100.1');
        self::assertSame('identity_mismatch', $this->browser->view()['phase']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM avatar_imports')->fetchColumn());
        $this->browser->restart('198.51.100.1');
        $query = $this->authorization((string) $this->browser->continueAction('198.51.100.1'));
        $this->flow->direct(['state' => $query['state'], 'error' => 'access_denied'], '198.51.100.1');
        self::assertSame('identity_mismatch', $this->browser->view()['phase']);
        self::assertNull($this->rows->require($start['id'])['provider_state_hash']);
        $this->browser->cancel('198.51.100.1');
        self::assertSame('cancelled', $this->browser->view()['phase']);
    }

    private function rejects(string $code, callable $operation): void
    {
        try { $operation(); self::fail('Invalid native browser operation was accepted.'); }
        catch (ApiException $exception) { self::assertSame($code, $exception->errorCode); }
    }
}