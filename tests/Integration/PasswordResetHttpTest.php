<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\PasswordRecoveryKey;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestScratch;

final class PasswordResetHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function testRealHttpRecoveryContractCsrfAtomicMfaKeyDisclosureAndRetryMetadata(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $account = $auth->registerWithRecoveryKey('Root', 'initial strong secret', '127.0.0.1');
        $mfaAccount = $auth->registerWithRecoveryKey('Member', 'initial strong secret', '127.0.0.2');
        $passkey = TestPasskey::create();
        $passkey->seed($this->pdo, $mfaAccount['user']->id);
        $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0, registration_min_fill_seconds = 0 WHERE id = 1');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('reset-http-log');
        $sessions = TestScratch::directory('reset-http-sessions');
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['WEBAUTHN_RP_ID'] = 'chat.test';
        $environment['WEBAUTHN_ORIGIN'] = 'https://chat.test';
        $environment['RATE_LIMIT_PASSWORD_RESET_MAX_ATTEMPTS'] = '4';
        $environment['RATE_LIMIT_PASSWORD_RESET_WINDOW_SECONDS'] = '60';
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $replacement = '';
        $generated = '';
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            [$status, $session] = $this->request('old', '/api/v1/session.php');
            self::assertSame(200, $status);
            self::assertNull($session['user']);
            self::assertSame(200, $this->request('old', '/api/v1/login.php',
                ['username' => 'Root', 'password' => 'initial strong secret'])[0]);
            $proof = $this->challenge('reset');
            self::assertSame(0, $proof['bits']);
            $payload = ['username' => 'Root', 'recovery_key' => $account['recovery_key'],
                'new_password' => 'replacement strong secret', 'challenge_nonce' => $proof['nonce'], 'challenge_solution' => '0'];
            [$status, $error] = $this->request('reset', '/api/v1/password-reset.php', $payload, false);
            self::assertSame(403, $status);
            self::assertSame('csrf_failed', $error['error']['code']);
            [$status, $complete] = $this->request('reset', '/api/v1/password-reset.php', $payload);
            self::assertSame(200, $status);
            self::assertSame('complete', $complete['status']);
            $replacement = $complete['recovery_key'];
            self::assertNotSame($account['recovery_key'], $replacement);
            self::assertTrue($complete['user']['recovery_key']['exists']);
            [$status, $session] = $this->request('old', '/api/v1/session.php');
            self::assertSame(200, $status);
            self::assertNull($session['user']); // Old browser session was invalidated.
            [$status, $session] = $this->request('reset', '/api/v1/session.php');
            self::assertSame(200, $status);
            self::assertTrue($session['user']['recovery_key']['exists']);
            self::assertFalse($session['security']['privileged_step_up']['active']);
            self::assertArrayNotHasKey('recovery_key', $session);
            self::assertSame(403, $this->request('reset', '/api/v1/account/recovery-key.php', [])[0]);
            self::assertSame(200, $this->request('reset', '/api/v1/step-up.php', ['password' => 'replacement strong secret'])[0]);
            [$status, $rotated] = $this->request('reset', '/api/v1/account/recovery-key.php', []);
            self::assertSame(200, $status);
            $generated = $rotated['recovery_key'];
            self::assertNotSame($replacement, $generated);
            self::assertIsString($rotated['created_at']);
            self::assertSame(PasswordRecoveryKey::hash($generated),
                $this->pdo->query('SELECT key_hash FROM password_recovery_keys WHERE user_id = ' . $account['user']->id)->fetchColumn());
            [$status, $error] = $this->request('reset', '/api/v1/password-reset/cancel.php', []);
            self::assertSame(409, $status);
            self::assertSame('reset_not_pending', $error['error']['code']);

            $proof = $this->challenge('mfa');
            [$status, $pending] = $this->request('mfa', '/api/v1/password-reset.php', [
                'username' => 'Member', 'recovery_key' => $mfaAccount['recovery_key'],
                'new_password' => 'replacement strong secret', 'challenge_nonce' => $proof['nonce'], 'challenge_solution' => '0']);
            self::assertSame(202, $status);
            self::assertSame('pending_mfa', $pending['status']);
            self::assertArrayNotHasKey('recovery_key', $pending);
            self::assertSame(200, $this->request('mfa', '/api/v1/password-reset/cancel.php', [])[0]);
            self::assertSame(409, $this->request('mfa', '/api/v1/password-reset/cancel.php', [])[0]);
            $proof = $this->challenge('mfa');
            self::assertSame(202, $this->request('mfa', '/api/v1/password-reset.php', [
                'username' => 'Member', 'recovery_key' => $mfaAccount['recovery_key'],
                'new_password' => 'replacement strong secret', 'challenge_nonce' => $proof['nonce'], 'challenge_solution' => '0'])[0]);
            [$status, $options] = $this->request('mfa', '/api/v1/password-reset/mfa-options.php', []);
            self::assertSame(200, $status);
            self::assertArrayHasKey('public_key', $options);
            [$status, $done] = $this->request('mfa', '/api/v1/password-reset/mfa-finish.php', ['credential' => $passkey->assertion($options['public_key'])]);
            self::assertSame(200, $status);
            self::assertSame('complete', $done['status']);
            self::assertNotSame($mfaAccount['recovery_key'], $done['recovery_key']);
            $mfaReplacement = $done['recovery_key'];
            $proof = $this->challenge('mfa-code');
            self::assertSame(202, $this->request('mfa-code', '/api/v1/password-reset.php', [
                'username' => 'Member', 'recovery_key' => $mfaReplacement,
                'new_password' => 'second replacement secret', 'challenge_nonce' => $proof['nonce'], 'challenge_solution' => '0'])[0]);
            [$status, $done] = $this->request('mfa-code', '/api/v1/password-reset/mfa-recovery.php', ['code' => $passkey->recoveryCode]);
            self::assertSame(200, $status);
            self::assertSame('complete', $done['status']);

            // Difficulty zero still needs the session's one-use nonce; the same request cannot replay.
            $proof = $this->challenge('invalid');
            $invalid = ['username' => 'Unknown', 'recovery_key' => $account['recovery_key'],
                'new_password' => 'replacement strong secret', 'challenge_nonce' => $proof['nonce'], 'challenge_solution' => '0'];
            self::assertSame(400, $this->request('invalid', '/api/v1/password-reset.php', $invalid)[0]);
            self::assertSame(400, $this->request('invalid', '/api/v1/password-reset.php', $invalid)[0]);
            $proof = $this->challenge('invalid');
            $invalid['challenge_nonce'] = $proof['nonce'];
            self::assertSame(400, $this->request('invalid', '/api/v1/password-reset.php', $invalid)[0]);
            $proof = $this->challenge('invalid');
            $invalid['challenge_nonce'] = $proof['nonce'];
            self::assertSame(400, $this->request('invalid', '/api/v1/password-reset.php', $invalid)[0]);
            $proof = $this->challenge('invalid');
            $invalid['challenge_nonce'] = $proof['nonce'];
            [$status, $error] = $this->request('invalid', '/api/v1/password-reset.php', $invalid);
            self::assertSame(429, $status);
            self::assertSame('rate_limited', $error['error']['code']);
            self::assertGreaterThan(0, $error['error']['retry_after_seconds']);
            self::assertSame((string) $error['error']['retry_after_seconds'], $this->headers['retry-after']);
            $contents = file_get_contents($log);
            self::assertIsString($contents);
            foreach ([$account['recovery_key'], $mfaAccount['recovery_key'], $replacement, $generated,
                $passkey->recoveryCode, 'replacement strong secret'] as $secret) {
                self::assertStringNotContainsString($secret, $contents);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            TestScratch::remove($log);
            TestScratch::remove($sessions);
            foreach ($this->cookies as $cookie) {
                TestScratch::remove($cookie);
            }
        }
    }

    /** @return array{nonce:string,bits:int} */
    private function challenge(string $client): array
    {
        [$status, $body] = $this->request($client, '/api/v1/password-reset/challenge.php');
        self::assertSame(200, $status);
        self::assertNotEmpty($body['csrf_token']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $body['challenge']['nonce']);
        return $body['challenge'];
    }

    /**
     * @param ?array<string,mixed> $payload
     * @return array{int,array<string,mixed>}
     */
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('reset-cookie-' . $client);
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
