<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthBootstrap;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Tests\Support\NativeOidcProvider;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestScratch;

/** Real HTTP with deliberately separate app/browser jars. Withheld Strict cookies are simulated explicitly;
 * the fixture is HTTP loopback, not proof of Android Custom Tabs or production TLS cookie delivery.
 */
final class NativeAuthHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    private string $providerUrl;
    private string $providerState;
    /** @var array<string,array<string,string>> */
    private array $jars = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var list<resource> */
    private array $processes = [];
    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->processes) as $process) { proc_terminate($process); proc_close($process); }
        foreach (array_reverse($this->scratch) as $path) TestScratch::remove($path);
        parent::tearDown();
    }

    private function servers(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0, registration_min_fill_seconds = 0 WHERE id = 1');
        $this->pdo->exec("INSERT INTO user_identities(user_id,provider,subject) VALUES ($member->id,'google','subject')");
        $root = dirname(__DIR__, 2);
        $this->providerState = TestScratch::file('native-http-provider-state');
        $this->scratch[] = $this->providerState;
        $address = $this->address();
        $this->providerUrl = 'http://' . $address;
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        file_put_contents($this->providerState, json_encode(['issuer' => $this->providerUrl, 'pem' => $pem, 'subject' => 'subject'], JSON_THROW_ON_ERROR));
        $environment = array_merge(getenv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'APP_ORIGIN' => 'https://chat.test',
            'OIDC_REDIRECT_ORIGIN' => 'https://chat.test', 'GOOGLE_OIDC_CLIENT_ID' => NativeOidcProvider::CLIENT_ID,
            'GOOGLE_OIDC_CLIENT_SECRET' => NativeOidcProvider::CLIENT_SECRET, 'OIDC_GOOGLE_ENDPOINT_OVERRIDE' => $this->providerUrl,
            'TWITCH_OIDC_CLIENT_ID' => '', 'TWITCH_OIDC_CLIENT_SECRET' => '', 'OIDC_TWITCH_ENDPOINT_OVERRIDE' => '',
            'SESSION_COOKIE_SECURE' => '0', 'SESSION_COOKIE_SAMESITE' => 'Strict',
            'WEBAUTHN_RP_ID' => 'chat.test', 'WEBAUTHN_ORIGIN' => 'https://chat.test',
            'CC_NATIVE_PROVIDER_FIXTURE' => $this->providerState,
            'NATIVE_ANDROID_APPS' => json_encode([['package' => 'de.networkxxiii.chitchat',
                'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]], JSON_THROW_ON_ERROR)]);
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $this->serve($address, [$root . '/tests/Support/native-oidc-router.php'], $environment);
        $address = $this->address();
        $this->baseUrl = 'http://' . $address;
        $this->serve($address, ['-t', $root . '/public'], $environment);
    }

    /** @param list<string> $arguments
     * @param array<string,string> $environment
     */
    private function serve(string $address, array $arguments, array $environment): void
    {
        $log = TestScratch::file('native-http-log');
        $sessions = TestScratch::directory('native-http-session');
        $this->scratch[] = $log;
        $this->scratch[] = $sessions;
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', $address, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->processes[] = $process;
        PhpHttpFixture::waitUntilReady($process, $address, $log);
    }

    private function address(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        return $address;
    }

    /** @param array<string,mixed>|null $payload
     * @return array{status:int,json:array<string,mixed>,body:string,headers:array<string,string>,cookies:list<string>}
     */
    private function request(string $jar, string $path, ?array $payload = null, bool $form = false): array
    {
        $headers = ['Connection: close'];
        $cookies = [];
        foreach ($this->jars[$jar] ?? [] as $name => $value) $cookies[] = $name . '=' . $value;
        if ($cookies !== []) $headers[] = 'Cookie: ' . implode('; ', $cookies);
        if ($payload !== null) {
            $headers[] = 'Content-Type: ' . ($form ? 'application/x-www-form-urlencoded' : 'application/json');
            if (!$form) $headers[] = 'X-CSRF-Token: ' . ($this->csrf[$jar] ?? '');
        }
        $context = stream_context_create(['http' => ['method' => $payload === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => $payload === null ? '' : ($form ? http_build_query($payload) : json_encode((object) $payload, JSON_THROW_ON_ERROR)),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20]]);
        $body = file_get_contents(str_starts_with($path, $this->providerUrl) ? $path : $this->baseUrl . $path, false, $context);
        self::assertIsString($body);
        $raw = http_get_last_response_headers();
        self::assertIsArray($raw);
        preg_match('/\AHTTP\/\S+ (\d+)/', $raw[0], $status);
        $resultHeaders = [];
        $setCookies = [];
        foreach ($raw as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            $resultHeaders[$name] = $value;
            if ($name === 'set-cookie') {
                $setCookies[] = $value;
                if (preg_match('/\A([^=;]+)=([^;]*)/', $value, $cookie) === 1) {
                    if ($cookie[2] === '' || $cookie[2] === 'deleted') unset($this->jars[$jar][$cookie[1]]);
                    else $this->jars[$jar][$cookie[1]] = $cookie[2];
                }
            }
        }
        $json = json_decode($body, true);
        $json = is_array($json) ? $json : [];
        if (is_string($json['csrf_token'] ?? null)) $this->csrf[$jar] = $json['csrf_token'];
        return ['status' => (int) ($status[1] ?? 0), 'json' => $json, 'body' => $body, 'headers' => $resultHeaders, 'cookies' => $setCookies];
    }

    /** @return array{transaction_id:string,browser_url:string,expires_at:string} */
    private function start(string $jar = 'native', string $purpose = 'login'): array
    {
        $this->request($jar, '/api/v1/session.php');
        $response = $this->request($jar, '/api/v1/native-auth/start.php', ['client_id' => 'de.networkxxiii.chitchat',
            'purpose' => $purpose, 'provider' => 'google', 'state' => NativeConfig::token(),
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256']);
        self::assertSame(200, $response['status'], $response['body']);
        self::assertStringStartsWith('https://chat.test/native-auth.php?', $response['json']['browser_url']);
        return $response['json'];
    }

    private function entryPath(string $url): string
    {
        return '/native-auth.php?' . parse_url($url, PHP_URL_QUERY);
    }

    private function csrfForm(string $jar): string
    {
        if (isset($this->jars[$jar][NativeAuthBootstrap::COOKIE])) {
            return NativeAuthBootstrap::parse($this->jars[$jar][NativeAuthBootstrap::COOKIE])['csrf_token'];
        }
        $this->request($jar, '/api/v1/session.php');
        return $this->csrf[$jar];
    }

    /** @return array<string,mixed> */
    private function providerCallback(string $location): array
    {
        self::assertStringStartsWith($this->providerUrl . '/authorize?', $location);
        $response = $this->request('provider', $location);
        self::assertSame(200, $response['status']);
        return $response['json'];
    }

    /** @return array<string,mixed> */
    private function approveAndGrant(string $jar): array
    {
        $response = $this->request($jar, '/api/v1/native-auth/browser/approve.php', ['csrf_token' => $this->csrfForm($jar)], true);
        self::assertSame(303, $response['status'], $response['body']);
        $page = $this->request($jar, '/native-auth.php');
        self::assertSame(200, $page['status'], $page['body']);
        self::assertSame(1, preg_match('/href="(de\.networkxxiii\.chitchat:\/native-auth\/callback\?[^" ]+)"/', $page['body'], $match), $page['body']);
        parse_str((string) parse_url(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'), PHP_URL_QUERY), $grant);
        self::assertSame(['code', 'transaction_id', 'state'], array_keys($grant));
        return [...$grant, 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'];
    }

    public function testRealHttpEntryDirectCallbackConsentExchangeAndReplayKeepTheBrowserSignedOut(): void
    {
        $this->servers();
        $unknown = $this->request('unknown', '/api/v1/oidc/callback.php?state=native.' . NativeConfig::token() . '&code=secret');
        self::assertSame(400, $unknown['status']);
        self::assertSame([], $unknown['cookies']);
        $start = $this->start();
        $entry = $this->request('browser', $this->entryPath($start['browser_url']));
        self::assertSame(303, $entry['status']);
        self::assertSame('/native-auth.php', $entry['headers']['location']);
        self::assertCount(1, $entry['cookies']);
        self::assertStringContainsString('__Host-chitchat-native=', $entry['cookies'][0]);
        foreach (['secure', 'HttpOnly', 'SameSite=Strict', 'path=/'] as $attribute) self::assertStringContainsString($attribute, $entry['cookies'][0]);
        $bad = $this->request('no-session', '/api/v1/native-auth/browser/continue.php', ['csrf_token' => 'wrong'], true);
        self::assertSame(403, $bad['status']);
        self::assertSame([], $bad['cookies']);
        $continue = $this->request('browser', '/api/v1/native-auth/browser/continue.php', ['csrf_token' => $this->csrfForm('browser')], true);
        self::assertSame(303, $continue['status'], $continue['body']);
        $query = $this->providerCallback($continue['headers']['location']);
        $callback = $this->request('browser', '/api/v1/oidc/callback.php?' . http_build_query($query));
        self::assertSame(303, $callback['status'], $callback['body']);
        self::assertSame('consent', (new NativeAuthRepository($this->pdo))->require($start['transaction_id'])['phase']);
        self::assertNull($this->request('browser', '/api/v1/session.php')['json']['user']);
        $before = $this->request('native', '/api/v1/native-auth/exchange.php', ['transaction_id' => $start['transaction_id'], 'state' => NativeConfig::token(), 'code' => NativeConfig::token(), 'code_verifier' => str_repeat('x', 43)]);
        self::assertSame('state_mismatch', $before['json']['error']['code']);
        $grant = $this->approveAndGrant('browser');
        self::assertSame(401, $this->request('browser', '/api/v1/native-auth/exchange.php', $grant)['status']);
        $beforeExchangeCookies = $this->jars['native'];
        $exchange = $this->request('native', '/api/v1/native-auth/exchange.php', $grant);
        self::assertSame(200, $exchange['status'], $exchange['body']);
        self::assertSame('completed', $exchange['json']['outcome']);
        self::assertSame('Member', $exchange['json']['user']['username']);
        self::assertSame('Member', $this->request('native', '/api/v1/session.php')['json']['user']['username']);
        self::assertNull($this->request('browser', '/api/v1/session.php')['json']['user']);
        $replay = $this->request('native', '/api/v1/native-auth/exchange.php', $grant);
        self::assertSame(409, $replay['status']);
        self::assertSame('already_used', $replay['json']['error']['code']);
        $status = $this->request('native', '/api/v1/native-auth/status.php?transaction_id=' . $start['transaction_id']);
        self::assertSame(['transaction_id', 'phase', 'outcome', 'error_code'], array_keys($status['json']));
        self::assertSame('used', $status['json']['phase']);
        self::assertSame('completed', $status['json']['outcome']);
        self::assertSame($status['json'], $this->request('native', '/api/v1/native-auth/status.php?transaction_id=' . $start['transaction_id'])['json']);
        // Simulate a lost Set-Cookie: the old anonymous app SID cannot replay a committed grant.
        $this->jars['lost-cookie'] = $beforeExchangeCookies;
        self::assertNull($this->request('lost-cookie', '/api/v1/session.php')['json']['user']);
        self::assertSame(401, $this->request('lost-cookie', '/api/v1/native-auth/exchange.php', $grant)['status']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
    }

    public function testStrictCallbackUsesTheOriginalBrowserJarAndNativeMfaRoutes(): void
    {
        $this->servers();
        $key = TestPasskey::create();
        $memberId = (int) $this->pdo->query("SELECT id FROM users WHERE username = 'Member'")->fetchColumn();
        $key->seed($this->pdo, $memberId);
        $start = $this->start();
        $this->request('browser', '/api/v1/session.php');
        $originalCookies = $this->jars['browser'];
        // Cross-site entry withholds the existing Strict SID. Only the new bootstrap travels back.
        $entry = $this->request('entry-hop', $this->entryPath($start['browser_url']));
        self::assertCount(1, $entry['cookies']);
        $this->jars['browser'][NativeAuthBootstrap::COOKIE] = $this->jars['entry-hop'][NativeAuthBootstrap::COOKIE];
        $continue = $this->request('browser', '/api/v1/native-auth/browser/continue.php', ['csrf_token' => $this->csrfForm('browser')], true);
        self::assertSame(303, $continue['status'], $continue['body']);
        foreach ($originalCookies as $name => $cookie) self::assertSame($cookie, $this->jars['browser'][$name]);
        $query = $this->providerCallback($continue['headers']['location']);
        $callback = $this->request('callback-hop', '/api/v1/oidc/callback.php?' . http_build_query($query));
        self::assertSame(303, $callback['status'], $callback['body']);
        self::assertCount(1, $callback['cookies']);
        $this->jars['browser'][NativeAuthBootstrap::COOKIE] = $this->jars['callback-hop'][NativeAuthBootstrap::COOKIE];
        $csrf = $this->csrfForm('browser');
        $this->jars['wrong-browser'][NativeAuthBootstrap::COOKIE] = $this->jars['callback-hop'][NativeAuthBootstrap::COOKIE];
        self::assertSame(403, $this->request('wrong-browser', '/api/v1/native-auth/browser/resume.php', ['csrf_token' => $csrf], true)['status']);
        $resumed = $this->request('browser', '/api/v1/native-auth/browser/resume.php', ['csrf_token' => $csrf], true);
        self::assertSame(303, $resumed['status'], $resumed['body']);
        foreach ($originalCookies as $name => $cookie) self::assertSame($cookie, $this->jars['browser'][$name]);
        $grant = $this->approveAndGrant('browser');
        $beforeExchangeCookies = $this->jars['native'];
        $exchange = $this->request('native', '/api/v1/native-auth/exchange.php', $grant);
        self::assertSame(202, $exchange['status'], $exchange['body']);
        self::assertSame('pending_mfa', $exchange['json']['outcome']);
        self::assertNull($exchange['json']['user']);
        $options = $this->request('native', '/api/v1/mfa/login-options.php', []);
        self::assertSame(200, $options['status'], $options['body']);
        $finish = $this->request('native', '/api/v1/mfa/login-finish.php', ['credential' => $key->assertion($options['json']['public_key'], 1)]);
        self::assertSame(200, $finish['status'], $finish['body']);
        self::assertSame('completed', $finish['json']['outcome']);
        self::assertSame($memberId, $finish['json']['user']['id']);
        self::assertNull($this->request('browser', '/api/v1/session.php')['json']['user']);
    }
    /** @param array{transaction_id:string,browser_url:string,expires_at:string} $start */
    private function verifyBrowser(array $start): void
    {
        self::assertSame(303, $this->request('browser', $this->entryPath($start['browser_url']))['status']);
        $continue = $this->request('browser', '/api/v1/native-auth/browser/continue.php', ['csrf_token' => $this->csrfForm('browser')], true);
        self::assertSame(303, $continue['status'], $continue['body']);
        $query = $this->providerCallback($continue['headers']['location']);
        self::assertSame(303, $this->request('browser', '/api/v1/oidc/callback.php?' . http_build_query($query))['status']);
    }

    /** @return array{challenge_nonce:string,challenge_solution:string} */
    private function challenge(): array
    {
        $response = $this->request('native', '/api/v1/registration-challenge.php');
        self::assertSame(200, $response['status']);
        $challenge = $response['json']['challenge'];
        $solution = 0;
        while (!\ChitChat\Auth\RegistrationChallenge::meetsDifficulty(hash('sha256', $challenge['nonce'] . ':' . $solution, true), $challenge['bits'])) $solution++;
        return ['challenge_nonce' => $challenge['nonce'], 'challenge_solution' => (string) $solution];
    }

    public function testNativeSignUpUsesTheExistingChallengeThenCurrentRulesBeforeCreatingAnAccount(): void
    {
        $this->servers();
        $state = json_decode((string) file_get_contents($this->providerState), true, 16, JSON_THROW_ON_ERROR);
        $state['subject'] = 'new-native-subject';
        file_put_contents($this->providerState, json_encode($state, JSON_THROW_ON_ERROR));
        $start = $this->start();
        $this->verifyBrowser($start);
        $grant = $this->approveAndGrant('browser');
        $result = $this->request('native', '/api/v1/native-auth/exchange.php', $grant);
        self::assertSame(202, $result['status'], $result['body']);
        self::assertSame('pending_sign_up', $result['json']['outcome']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $root = (new \ChitChat\Auth\UserRepository($this->pdo))->findAuthenticatedById(1);
        (new \ChitChat\Policy\PolicyService($this->pdo, $this->config))->update($root, 'rules', 'hosted', 'Be kind.', null, true, 'First publish', '192.0.2.1');
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 1 WHERE id = 1');
        $bad = $this->request('native', '/api/v1/oidc/register.php', ['username' => 'NewNative', 'challenge_nonce' => 'wrong', 'challenge_solution' => '0']);
        self::assertSame(400, $bad['status']);
        self::assertSame('registration_challenge_missing', $bad['json']['error']['code']);
        $rules = $this->request('native', '/api/v1/oidc/register.php', ['username' => 'NewNative', ...$this->challenge()]);
        self::assertSame(400, $rules['status'], $rules['body']);
        self::assertSame('rules_acceptance_required', $rules['json']['error']['code']);
        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $created = $this->request('native', '/api/v1/oidc/register.php', ['username' => 'NewNative', 'accepted_rules_version' => 1, ...$this->challenge()]);
        self::assertSame(201, $created['status'], $created['body']);
        self::assertSame('completed', $created['json']['outcome']);
        self::assertSame('sign_up', $created['json']['purpose']);
        self::assertSame('NewNative', $created['json']['user']['username']);
        self::assertSame(1, $created['json']['user']['rules']['accepted_version']);
        self::assertNull($this->request('browser', '/api/v1/session.php')['json']['user']);
        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testBootstrapCancellationIsAcknowledgedAndReplayCannotCreateAnotherBrowserSession(): void
    {
        $this->servers();
        $start = $this->start();
        $this->request('browser', $this->entryPath($start['browser_url']));
        $bootstrap = $this->jars['browser'][NativeAuthBootstrap::COOKIE];
        $csrf = $this->csrfForm('browser');
        $cancel = $this->request('browser', '/api/v1/native-auth/browser/cancel.php', ['csrf_token' => $csrf], true);
        self::assertSame(303, $cancel['status'], $cancel['body']);
        $page = $this->request('browser', '/native-auth.php');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('data-phase="cancelled"', $page['body']);
        self::assertArrayNotHasKey(NativeAuthBootstrap::COOKIE, $this->jars['browser']);
        self::assertSame(['status' => 'cancelled'], $this->request('native', '/api/v1/native-auth/cancel.php', ['transaction_id' => $start['transaction_id']])['json']);
        $this->jars['replay'][NativeAuthBootstrap::COOKIE] = $bootstrap;
        $replay = $this->request('replay', '/api/v1/native-auth/browser/continue.php', ['csrf_token' => $csrf], true);
        self::assertSame(403, $replay['status']);
        self::assertArrayNotHasKey($this->config->sessionName, $this->jars['replay']);
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
    }
    public function testNativeThrottleReturnsIntegerRetryMetadataWithoutLeakingProof(): void
    {
        $this->servers();
        $this->request('native', '/api/v1/session.php');
        $payload = ['client_id' => 'de.networkxxiii.chitchat', 'provider' => 'google', 'purpose' => 'login',
            'state' => NativeConfig::token(), 'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256'];
        for ($i = 0; $i < 10; $i++) self::assertSame(200, $this->request('native', '/api/v1/native-auth/start.php', $payload)['status']);
        $limited = $this->request('native', '/api/v1/native-auth/start.php', $payload);
        self::assertSame(429, $limited['status']);
        self::assertSame('rate_limited', $limited['json']['error']['code']);
        self::assertIsInt($limited['json']['error']['retry_after_seconds']);
        self::assertGreaterThan(0, $limited['json']['error']['retry_after_seconds']);
        self::assertSame((string) $limited['json']['error']['retry_after_seconds'], $limited['headers']['retry-after']);
        self::assertStringNotContainsString($payload['state'], $limited['body']);
    }
}
