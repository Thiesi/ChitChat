<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\Totp\TotpKeyRing;
use ChitChat\Auth\Totp\TotpKeyStore;
use ChitChat\Auth\Totp\TotpProtocol;
use ChitChat\Auth\Totp\TotpRepository;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Tests\Support\TestTotp;

/** Run after the required presentation marker is enabled on the integrated branch. */
final class TotpHttpTest extends DatabaseTestCase
{
    private string $base;
    private array $cookies = [];
    private array $csrf = [];
    private array $headers = [];

    public function testProductionRoutesPreserveCsrfLoginBindingMfaAuthorityOneUseSecretsAndRetryMetadata(): void
    {
        $key = random_bytes(32);
        $keys = json_encode(['fixture' => base64_encode($key)], JSON_THROW_ON_ERROR);
        $this->config = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson($keys, 'fixture')]);
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'initial strong secret', '127.0.0.1');
        $member = $auth->register('Member', 'initial strong secret', '127.0.0.2');
        $memberSeed = TestTotp::seed($this->pdo, $this->config, $member->id);
        $this->pdo->exec('UPDATE system_settings SET registration_min_fill_seconds = 0, registration_proof_of_work_bits = 0 WHERE id = 1');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->base = 'http://' . $address;
        $log = TestScratch::file('totp-http-log');
        $sessions = TestScratch::directory('totp-http-sessions');
        $environment = getenv();
        $environment['TOTP_ENCRYPTION_KEYS'] = $keys;
        $environment['TOTP_ACTIVE_KEY_ID'] = 'fixture';
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', $address,
            '-t', dirname(__DIR__, 2) . '/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes, dirname(__DIR__, 2), $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            foreach (['one', 'two', 'member'] as $jar) {
                [$status, $bootstrap] = $this->request($jar, '/api/v1/session.php');
                self::assertSame(200, $status);
                self::assertTrue($bootstrap['capabilities']['totp'], 'Enrollment ships only with integrated web UI.');
            }
            foreach (['one', 'two'] as $jar) {
                self::assertSame(200, $this->request($jar, '/api/v1/login.php', ['username' => 'Root', 'password' => 'initial strong secret'])[0]);
            }
            $beginRoute = '/api/v1/account/mfa/totp/begin.php';
            self::assertSame('csrf_failed', $this->request('one', $beginRoute, [], false)[1]['error']['code']);
            self::assertSame('step_up_required', $this->request('one', $beginRoute, [])[1]['error']['code']);
            foreach (['one', 'two'] as $jar) {
                self::assertSame(200, $this->request($jar, '/api/v1/step-up.php', ['password' => 'initial strong secret'])[0]);
            }
            [$status, $begin] = $this->request('one', $beginRoute, []);
            self::assertSame(200, $status);
            self::assertSame(32, strlen($begin['secret']));
            self::assertSame('SHA1', $begin['algorithm']);
            self::assertSame(6, $begin['digits']);
            self::assertSame(30, $begin['period_seconds']);
            self::assertStringStartsWith('otpauth://totp/', $begin['otpauth_uri']);
            self::assertSame(405, $this->request('one', $beginRoute)[0]);
            $pending = (new TotpRepository($this->pdo))->credential($root->id, 'pending');
            self::assertNotNull($pending);
            $seed = $pending->decrypt(new TotpKeyStore($this->pdo, $this->config));
            $proof = TotpProtocol::codeAtStep($seed, intdiv(time(), 30));
            $confirm = ['enrollment_id' => $begin['enrollment_id'], 'code' => $proof];
            self::assertSame('totp_enrollment_expired',
                $this->request('two', '/api/v1/account/mfa/totp/confirm.php', $confirm)[1]['error']['code']);
            [$status, $completed] = $this->request('one', '/api/v1/account/mfa/totp/confirm.php', $confirm);
            self::assertSame(200, $status);
            self::assertCount(10, $completed['recovery_codes']);
            self::assertTrue($completed['mfa']['totp']['enabled']);
            self::assertContains('totp', $completed['mfa']['usable_methods']);
            self::assertArrayNotHasKey('secret', $completed);
            self::assertFalse($this->request('two', '/api/v1/session.php')[1]['security']['privileged_step_up']['active']);
            self::assertSame($root->id, $this->request('two', '/api/v1/session.php')[1]['user']['id']);
            self::assertSame('invalid_totp', $this->request('one', '/api/v1/mfa/step-up-totp.php', ['code' => $proof])[1]['error']['code']);
            $future = TotpProtocol::codeAtStep($seed, intdiv(time(), 30) + 1);
            [$status, $stepUp] = $this->request('one', '/api/v1/mfa/step-up-totp.php', ['code' => substr($future, 0, 3) . '-' . substr($future, 3)]);
            self::assertSame(200, $status);
            self::assertSame('totp', $stepUp['security']['privileged_step_up']['method']);
            self::assertTrue($stepUp['security']['privileged_step_up']['active']);
            self::assertSame('last_mfa_method', $this->request('one', '/api/v1/account/mfa/totp/remove.php', [])[1]['error']['code']);
            $setting = $this->request('one', '/api/v1/admin/settings/totp/get.php')[1]['totp'];
            self::assertSame('server', $setting['mode']);
            self::assertFalse($setting['database_key_switch_available']);
            self::assertArrayNotHasKey('key', $setting);
            self::assertSame('totp_server_keys_configured',
                $this->request('one', '/api/v1/admin/settings/totp/update.php', ['database_key_enabled' => true])[1]['error']['code']);
            [$status, $replacement] = $this->request('one', $beginRoute, []);
            self::assertSame(200, $status);
            self::assertSame(['cancelled' => true], $this->request('one', '/api/v1/account/mfa/totp/cancel.php',
                ['enrollment_id' => $replacement['enrollment_id']])[1]);

            [$status, $login] = $this->request('member', '/api/v1/login.php', ['username' => 'Member', 'password' => 'initial strong secret']);
            self::assertSame(202, $status);
            self::assertContains('totp', $login['methods']);
            $pendingBootstrap = $this->request('member', '/api/v1/session.php')[1];
            self::assertSame(['methods'], array_keys($pendingBootstrap['mfa_pending']));
            self::assertContains('totp', $pendingBootstrap['mfa_pending']['methods']);
            self::assertNull($pendingBootstrap['user']);
            $memberProof = TotpProtocol::codeAtStep($memberSeed, intdiv(time(), 30));
            [$status, $signedIn] = $this->request('member', '/api/v1/mfa/login-totp.php', ['code' => $memberProof]);
            self::assertSame(200, $status);
            self::assertSame('totp', $signedIn['mfa_method']);
            self::assertSame($member->id, $signedIn['user']['id']);
            self::assertNull($this->request('member', '/api/v1/session.php')[1]['mfa_pending']);
            self::assertSame('forbidden', $this->request('member', '/api/v1/admin/settings/totp/get.php')[1]['error']['code']);

            $this->pdo->exec("UPDATE request_rate_limits SET attempt_count = 10 WHERE scope = 'totp_verification'");
            [$status, $limited] = $this->request('one', '/api/v1/mfa/step-up-totp.php', ['code' => '123456']);
            self::assertSame(429, $status);
            self::assertSame('rate_limited', $limited['error']['code']);
            self::assertGreaterThan(0, $limited['error']['retry_after_seconds']);
            self::assertSame((string) $limited['error']['retry_after_seconds'], $this->headers['retry-after']);
            $audit = (string) $this->pdo->query('SELECT json_agg(audit_log)::text FROM audit_log')->fetchColumn();
            foreach ([$begin['secret'], $replacement['secret'], $begin['otpauth_uri'], base64_encode($key)] as $secret) {
                self::assertStringNotContainsString($secret, $audit);
                self::assertStringNotContainsString($secret, (string) file_get_contents($log));
            }
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
            TestScratch::remove($log);
            TestScratch::remove($sessions);
            foreach ($this->cookies as $path) TestScratch::remove($path);
        }
    }

    private function request(string $jar, string $path, ?array $payload = null, bool $csrf = true): array
    {
        $cookie = $this->cookies[$jar] ??= TestScratch::file('totp-cookie-' . $jar);
        $curl = curl_init($this->base . $path);
        self::assertNotFalse($curl);
        $this->headers = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $this->headers[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($payload !== null) curl_setopt_array($curl, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($csrf ? ($this->csrf[$jar] ?? '') : '')]]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringContainsString('no-store', $this->headers['cache-control'] ?? '');
        if (is_string($decoded['csrf_token'] ?? null)) $this->csrf[$jar] = $decoded['csrf_token'];
        return [$status, $decoded];
    }
}