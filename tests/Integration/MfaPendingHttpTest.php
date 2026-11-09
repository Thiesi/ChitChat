<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestPasskey;
use ChitChat\Tests\Support\TestScratch;

final class MfaPendingHttpTest extends DatabaseTestCase
{
    private string $base;
    private array $cookies = [];
    private array $csrf = [];
    private array $headers = [];

    public function testSessionDiscoveryIsPrivateAndInvalidationReturnsTheCurrentCsrfBeforeAnotherLogin(): void
    {
        $user = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        TestPasskey::create()->seed($this->pdo, $user->id);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->base = 'http://' . $address;
        $log = TestScratch::file('pending-mfa-http-log');
        $sessions = TestScratch::directory('pending-mfa-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', $address,
            '-t', dirname(__DIR__, 2) . '/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            foreach (['owner', 'other'] as $jar) {
                [$status, $initial] = $this->request($jar, '/api/v1/session.php');
                self::assertSame(200, $status);
                self::assertNull($initial['mfa_pending']);
            }
            [$status, $pending] = $this->request('owner', '/api/v1/login.php',
                ['username' => 'Member', 'password' => 'a very secure password']);
            self::assertSame(202, $status);
            [$status, $bootstrap] = $this->request('owner', '/api/v1/session.php');
            self::assertSame(200, $status);
            self::assertNull($bootstrap['user']);
            self::assertSame(['methods' => $pending['methods']], $bootstrap['mfa_pending']);
            self::assertNull($this->request('other', '/api/v1/session.php')[1]['mfa_pending']);
            $previousCsrf = $bootstrap['csrf_token'];
            $this->pdo->exec('UPDATE users SET session_version = session_version + 1 WHERE id = ' . $user->id);
            [$status, $invalidated] = $this->request('owner', '/api/v1/session.php');
            self::assertSame(200, $status);
            self::assertNull($invalidated['mfa_pending']);
            self::assertNotSame($previousCsrf, $invalidated['csrf_token']);
            self::assertSame(202, $this->request('owner', '/api/v1/login.php',
                ['username' => 'Member', 'password' => 'a very secure password'])[0],
                'The bootstrap must return the rotated CSRF, not the token read before pending-state reconciliation.');
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
