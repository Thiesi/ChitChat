<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\Base64Url;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestScratch;

final class NativePasskeyHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function testConfiguredNativeCredentialUsesExistingEnrollmentLoginStepUpRestoreAndResetRoutes(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'a very secure password', '127.0.0.2');
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0 WHERE id = 1');
        $key = TestPasskey::create();
        $origin = 'android:apk-key-hash:' . rtrim(strtr(base64_encode(str_repeat("\xab", 32)), '+/', '-_'), '=');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $root = dirname(__DIR__, 2);
        $log = TestScratch::file('native-passkey-http-log');
        $sessions = TestScratch::directory('native-passkey-http-sessions');
        $environment = array_merge(getenv(), ['APP_ENV' => 'test', 'SESSION_COOKIE_SECURE' => '0',
            'WEBAUTHN_RP_ID' => 'chat.test', 'WEBAUTHN_ORIGIN' => 'https://chat.test',
            'NATIVE_ANDROID_APPS' => json_encode([['package' => 'de.networkxxiii.chitchat',
                'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]], JSON_THROW_ON_ERROR)]);
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', $root . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $anonymous = $this->request('anonymous', '/api/v1/session.php')[1];
            self::assertTrue($anonymous['capabilities']['native_passkey_origins']);
            self::assertFalse($anonymous['capabilities']['native_auth_handoff']);
            self::assertSame(401, $this->request('anonymous', '/api/v1/account/mfa/register-options.php', [])[0]);
            $this->request('member', '/api/v1/session.php');
            self::assertSame(200, $this->request('member', '/api/v1/login.php',
                ['username' => 'Member', 'password' => 'a very secure password'])[0]);
            self::assertSame(200, $this->request('member', '/api/v1/step-up.php', ['password' => 'a very secure password'])[0]);
            [$status, $options] = $this->request('member', '/api/v1/account/mfa/register-options.php', []);
            self::assertSame(200, $status);
            self::assertSame('chat.test', $options['public_key']['rp']['id']);
            $key = new TestPasskey($key->privateKey, $key->credentialId, $key->cose,
                Base64Url::decode($options['public_key']['user']['id']), $key->recoveryCode);
            [$status, $enrolled] = $this->request('member', '/api/v1/account/mfa/register-finish.php',
                ['credential' => $key->registration($options['public_key'], $origin), 'label' => 'Native fixture']);
            self::assertSame(201, $status, json_encode($enrolled, JSON_THROW_ON_ERROR));
            self::assertCount(10, $enrolled['recovery_codes']);
            self::assertTrue($enrolled['mfa']['enabled']);
            self::assertSame(200, $this->request('member', '/api/v1/logout.php', [])[0]);
            $this->request('member', '/api/v1/session.php');
            self::assertSame(202, $this->request('member', '/api/v1/login.php',
                ['username' => 'Member', 'password' => 'a very secure password'])[0]);
            self::assertNull($this->request('member', '/api/v1/session.php')[1]['user']);
            $options = $this->request('member', '/api/v1/mfa/login-options.php', [])[1]['public_key'];
            self::assertSame(403, $this->request('member', '/api/v1/mfa/login-finish.php',
                ['credential' => $key->assertion($options, 1, 'android:apk-key-hash:' . str_repeat('A', 43))])[0]);
            self::assertNull($this->request('member', '/api/v1/session.php')[1]['user']);
            $options = $this->request('member', '/api/v1/mfa/login-options.php', [])[1]['public_key'];
            [$status, $signedIn] = $this->request('member', '/api/v1/mfa/login-finish.php',
                ['credential' => $key->assertion($options, 1, $origin)]);
            self::assertSame(200, $status);
            self::assertSame($member->id, $signedIn['user']['id']);
            self::assertSame($member->id, $this->request('member', '/api/v1/session.php')[1]['user']['id']);
            $options = $this->request('member', '/api/v1/mfa/step-up-options.php', [])[1]['public_key'];
            [$status, $elevated] = $this->request('member', '/api/v1/mfa/step-up-finish.php',
                ['credential' => $key->assertion($options, 2, $origin)]);
            self::assertSame(200, $status);
            self::assertTrue($elevated['security']['privileged_step_up']['active']);
            self::assertSame('passkey', $elevated['security']['privileged_step_up']['method']);
            [$status, $recovery] = $this->request('member', '/api/v1/account/recovery-key.php', []);
            self::assertSame(200, $status);
            self::assertSame(200, $this->request('member', '/api/v1/account/close.php', [])[0]);
            self::assertSame('closure_pending', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $member->id)->fetchColumn());
            $this->request('member', '/api/v1/session.php');
            self::assertSame(202, $this->request('member', '/api/v1/account/restore.php',
                ['username' => 'Member', 'password' => 'a very secure password'])[0]);
            self::assertSame('closure_pending', $this->pdo->query('SELECT account_state FROM users WHERE id = ' . $member->id)->fetchColumn());
            $options = $this->request('member', '/api/v1/mfa/login-options.php', [])[1]['public_key'];
            [$status, $restored] = $this->request('member', '/api/v1/mfa/login-finish.php',
                ['credential' => $key->assertion($options, 3, $origin)]);
            self::assertSame(200, $status);
            self::assertTrue($restored['restored']);
            self::assertSame($member->id, $restored['user']['id']);
            $challenge = $this->request('reset', '/api/v1/password-reset/challenge.php')[1]['challenge'];
            self::assertSame(0, $challenge['bits']);
            self::assertSame(202, $this->request('reset', '/api/v1/password-reset.php', [
                'username' => 'Member', 'recovery_key' => $recovery['recovery_key'],
                'new_password' => 'replacement strong secret', 'challenge_nonce' => $challenge['nonce'], 'challenge_solution' => '0'])[0]);
            $options = $this->request('reset', '/api/v1/password-reset/mfa-options.php', [])[1]['public_key'];
            [$status, $reset] = $this->request('reset', '/api/v1/password-reset/mfa-finish.php',
                ['credential' => $key->assertion($options, 4, $origin)]);
            self::assertSame(200, $status);
            self::assertSame('complete', $reset['status']);
            self::assertSame($member->id, $reset['user']['id']);
            self::assertNotSame($recovery['recovery_key'], $reset['recovery_key']);
            self::assertNull($this->request('member', '/api/v1/session.php')[1]['user'], 'Reset invalidates the old session.');
            self::assertSame(400, $this->request('reset', '/api/v1/password-reset/mfa-finish.php',
                ['credential' => $key->assertion($options, 4, $origin)])[0]);
            $audit = (string) $this->pdo->query("SELECT string_agg(metadata_json::text, '') FROM audit_log")->fetchColumn();
            self::assertStringNotContainsString($origin, $audit);
            self::assertStringNotContainsString($recovery['recovery_key'], $audit);
            self::assertStringNotContainsString($recovery['recovery_key'], (string) file_get_contents($log));
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
            TestScratch::remove($log);
            TestScratch::remove($sessions);
            foreach ($this->cookies as $cookie) TestScratch::remove($cookie);
        }
    }

    /**
     * @param ?array<string,mixed> $payload
     * @return array{int,array<string,mixed>}
     */
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('native-passkey-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        $this->headers = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $this->headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($withCsrf ? ($this->csrf[$client] ?? '') : '')]]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringContainsString('no-store', $this->headers['cache-control'] ?? '');
        if (isset($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
            $this->csrf[$client] = $decoded['csrf_token'];
        }
        return [$status, $decoded];
    }
}
