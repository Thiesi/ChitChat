<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class LoginSessionHttpTest extends DatabaseTestCase
{
    private string $base;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function testSeparateJarsOwnershipCsrfStepUpRevocationReauthenticationLogoutAndRetryContract(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $actor = $auth->register('Member', 'a very secure password', '127.0.0.1');
        $other = $auth->register('Other', 'different secure passphrase', '127.0.0.2');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->base = 'http://' . $address;
        $log = TestScratch::file('sessions-http-log');
        $sessions = TestScratch::directory('sessions-http-state');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', $address,
            '-t', dirname(__DIR__, 2) . '/public'], [0 => ['pipe','r'],1 => ['file',$log,'a'],2 => ['file',$log,'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            foreach (['one','two','three','logout','guest','reset','foreign'] as $jar) {
                [$status, $bootstrap] = $this->request($jar, '/api/v1/session.php');
                self::assertSame(200, $status);
                self::assertTrue($bootstrap['capabilities']['signed_in_sessions']);
                self::assertSame(200, $this->request($jar, '/api/v1/login.php',
                    ['username' => $jar === 'foreign' ? 'Other' : 'Member',
                        'password' => $jar === 'foreign' ? 'different secure passphrase' : 'a very secure password'])[0]);
            }
            [$status, $list] = $this->request('one', '/api/v1/account/sessions/list.php');
            self::assertSame(200, $status);
            self::assertSame(50, $list['maximum_sessions']);
            self::assertCount(6, $list['sessions']);
            $current = $list['sessions'][0]['id'];
            self::assertTrue($list['sessions'][0]['current']);
            $second = $this->request('two', '/api/v1/account/sessions/list.php')[1]['sessions'][0]['id'];
            $foreign = $this->request('foreign', '/api/v1/account/sessions/list.php')[1]['sessions'][0]['id'];
            self::assertSame(403, $this->request('one', '/api/v1/account/sessions/revoke.php', ['id' => $second], false)[0]);
            self::assertSame('current_session', $this->request('one', '/api/v1/account/sessions/revoke.php', ['id' => $current])[1]['error']['code']);
            self::assertSame('session_not_found', $this->request('one', '/api/v1/account/sessions/revoke.php', ['id' => $foreign])[1]['error']['code']);
            self::assertSame('step_up_required', $this->request('one', '/api/v1/account/sessions/revoke-others.php', [])[1]['error']['code']);
            self::assertSame(200, $this->request('one', '/api/v1/step-up.php', ['password' => 'a very secure password'])[0]);
            self::assertSame(['revoked' => true], $this->request('one', '/api/v1/account/sessions/revoke.php', ['id' => $second])[1]);
            self::assertSame(['revoked' => true], $this->request('one', '/api/v1/account/sessions/revoke.php', ['id' => $second])[1]);
            [$status, $revoked] = $this->request('two', '/api/v1/account/sessions/list.php');
            self::assertSame(401, $status);
            self::assertSame('session_revoked', $revoked['error']['code']);
            self::assertNull($this->request('two', '/api/v1/session.php')[1]['user']);
            self::assertSame(200, $this->request('three', '/api/v1/account/sessions/list.php')[0]);
            self::assertSame(4, $this->request('one', '/api/v1/account/sessions/revoke-others.php', [])[1]['revoked_count']);
            self::assertSame(0, $this->request('one', '/api/v1/account/sessions/revoke-others.php', [])[1]['revoked_count']);
            self::assertSame(200, $this->request('logout', '/api/v1/logout.php', [])[0], 'A revoked holder can explicitly sign out.');
            self::assertNull($this->request('logout', '/api/v1/session.php')[1]['user']);
            self::assertSame(200, $this->request('reset', '/api/v1/password-reset/challenge.php')[0], 'A revoked holder can begin password recovery.');
            self::assertSame($current, $this->request('one', '/api/v1/account/sessions/list.php')[1]['sessions'][0]['id']);
            self::assertSame($actor->sessionVersion, (int) $this->pdo->query('SELECT session_version FROM users WHERE id = ' . $actor->id)->fetchColumn());
            self::assertSame('session_revoked', $this->request('three', '/api/v1/account/sessions/list.php')[1]['error']['code']);
            $this->request('three', '/api/v1/session.php');
            self::assertSame(200, $this->request('three', '/api/v1/login.php', ['username' => 'Member', 'password' => 'a very secure password'])[0]);
            self::assertCount(2, $this->request('one', '/api/v1/account/sessions/list.php')[1]['sessions']);
            self::assertSame(200, $this->request('three', '/api/v1/logout.php', [])[0]);
            self::assertCount(1, $this->request('one', '/api/v1/account/sessions/list.php')[1]['sessions']);
            $this->pdo->exec("UPDATE request_rate_limits SET attempt_count = 60 WHERE scope = 'sessions_management'");
            [$status, $limited] = $this->request('one', '/api/v1/account/sessions/revoke-others.php', []);
            self::assertSame(429, $status);
            self::assertSame('rate_limited', $limited['error']['code']);
            self::assertGreaterThan(0, $limited['error']['retry_after_seconds']);
            self::assertSame((string) $limited['error']['retry_after_seconds'], $this->headers['retry-after']);
            $this->pdo->exec("UPDATE system_settings SET guest_access_enabled = TRUE, registration_min_fill_seconds = 0, registration_proof_of_work_bits = 0 WHERE id = 1");
            // This jar still carries its revoked member cookie: guest start must treat it as anonymous.
            [$status, $started] = $this->request('guest', '/api/v1/guest/start.php', []);
            self::assertSame(201, $status);
            self::assertTrue($started['user']['guest']);
            $guestId = $started['user']['id'];
            self::assertCount(1, $this->request('guest', '/api/v1/account/sessions/list.php')[1]['sessions']);
            self::assertSame(['revoked_count' => 0], $this->request('guest', '/api/v1/account/sessions/revoke-others.php', [])[1]);
            $this->pdo->exec('UPDATE users SET guest_last_seen_at = NOW() - interval \'3 hours\' WHERE id = ' . $guestId);
            self::assertSame(401, $this->request('guest', '/api/v1/account/sessions/list.php')[0]);
            (new \ChitChat\Auth\GuestService($this->pdo))->endExpired();
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions WHERE revoked_at IS NULL AND user_id = ' . $guestId)->fetchColumn());
            // Account A remains signed in on this jar while account B begins MFA sign-in.
            $this->request('relogin', '/api/v1/session.php');
            self::assertSame(200, $this->request('relogin', '/api/v1/login.php',
                ['username' => 'Other', 'password' => 'different secure passphrase'])[0]);
            $oldId = $this->request('relogin', '/api/v1/account/sessions/list.php')[1]['sessions'][0]['id'];
            $lookup = $this->pdo->prepare('SELECT binding_hash FROM login_sessions WHERE id = :id');
            $lookup->execute(['id' => $oldId]);
            $oldHash = $lookup->fetchColumn();
            self::assertIsString($oldHash);
            \ChitChat\Tests\Support\PushFixtures::subscriptions($this->pdo)->subscribe($other,
                'https://push.example/replaced-login', \ChitChat\Tests\Support\PushFixtures::key(),
                \ChitChat\Tests\Support\PushFixtures::auth(), null, $oldHash);
            $factor = \ChitChat\Tests\Support\TestPasskey::create();
            $factor->seed($this->pdo, $actor->id);
            self::assertSame(202, $this->request('relogin', '/api/v1/login.php',
                ['username' => 'Member', 'password' => 'a very secure password'])[0]);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
            self::assertSame(200, $this->request('relogin', '/api/v1/mfa/login-recovery.php',
                ['recovery_code' => $factor->recoveryCode])[0]);
            self::assertSame($actor->id, $this->request('relogin', '/api/v1/session.php')[1]['user']['id']);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM login_sessions WHERE id = '" . $oldId . "' AND revoked_at IS NOT NULL")->fetchColumn());
            self::assertCount(1, $this->request('foreign', '/api/v1/account/sessions/list.php')[1]['sessions'], 'A different browser of account A survives.');
            self::assertStringNotContainsString('a very secure password', file_get_contents($log) ?: '');
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
            TestScratch::remove($log);
            TestScratch::remove($sessions);
            foreach ($this->cookies as $cookie) TestScratch::remove($cookie);
        }
    }

    /** @param array<string,mixed>|null $payload
     * @return array{int,array<string,mixed>}
     */
    private function request(string $jar, string $path, ?array $payload = null, bool $csrf = true): array
    {
        $cookie = $this->cookies[$jar] ??= TestScratch::file('login-cookie-' . $jar);
        $curl = curl_init($this->base . $path);
        self::assertNotFalse($curl);
        $this->headers = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line): int {
                if (str_contains($line, ':')) {
                    [$key,$value] = explode(':',$line,2);
                    $this->headers[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($payload !== null) curl_setopt_array($curl, [CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json','X-CSRF-Token: ' . ($csrf ? ($this->csrf[$jar] ?? '') : '')]]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertStringContainsString('no-store', $this->headers['cache-control'] ?? '');
        if (is_string($decoded['csrf_token'] ?? null)) $this->csrf[$jar] = $decoded['csrf_token'];
        return [$status,$decoded];
    }
}
