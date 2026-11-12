<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class SignInDecorationHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testRealHttpGatesAuditAndPublicField(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('decoration-http-log');
        $sessions = TestScratch::directory('decoration-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        $base = '/api/v1/admin/settings/sign-in-decoration/';
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            [, $anonymous] = $this->request('anonymous', '/api/v1/session.php');
            self::assertIsArray($anonymous);
            self::assertArrayHasKey('sign_in_decoration', $anonymous);
            self::assertNull($anonymous['sign_in_decoration']);
            self::assertSame(401, $this->request('anonymous', $base . 'get.php')[0]);
            self::assertSame(401, $this->request('anonymous', $base . 'update.php', ['sign_in_decoration' => 'winter'])[0]);

            $this->login('admin', 'Admin', 'another secure password');
            [$status, $refused] = $this->request('admin', $base . 'get.php');
            self::assertSame([403, 'forbidden'], [$status, $refused['error']['code'] ?? null]);
            self::assertSame(200, $this->request('admin', '/api/v1/step-up.php', ['password' => 'another secure password'])[0]);
            [$status, $refused] = $this->request('admin', $base . 'update.php', ['sign_in_decoration' => 'winter']);
            self::assertSame([403, 'forbidden'], [$status, $refused['error']['code'] ?? null]);

            $this->login('root', 'Root', 'a very secure password');
            [$status, $current] = $this->request('root', $base . 'get.php');
            self::assertSame(200, $status);
            self::assertSame(['sign_in_decoration' => ['value' => 'off', 'options' => ['off', 'winter', 'spring', 'celebration']]], $current);
            [$status, $csrf] = $this->request('root', $base . 'update.php', ['sign_in_decoration' => 'winter'], false);
            self::assertSame([403, 'csrf_failed'], [$status, $csrf['error']['code'] ?? null]);
            [$status, $proof] = $this->request('root', $base . 'update.php', ['sign_in_decoration' => 'winter']);
            self::assertSame([403, 'step_up_required'], [$status, $proof['error']['code'] ?? null]);
            self::assertSame('off', $this->pdo->query('SELECT sign_in_decoration FROM system_settings')?->fetchColumn(), 'Nothing changes without step-up.');
            self::assertSame(200, $this->request('root', '/api/v1/step-up.php', ['password' => 'a very secure password'])[0]);
            [$status, $invalid] = $this->request('root', $base . 'update.php', ['sign_in_decoration' => 'autumn']);
            self::assertSame([400, 'validation_error'], [$status, $invalid['error']['code'] ?? null]);
            [$status, $missing] = $this->request('root', $base . 'update.php', []);
            self::assertSame([400, 'validation_error'], [$status, $missing['error']['code'] ?? null]);

            [$status, $updated] = $this->request('root', $base . 'update.php', ['sign_in_decoration' => 'celebration']);
            self::assertSame(200, $status);
            self::assertSame('celebration', $updated['sign_in_decoration']['value'] ?? null);
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.sign_in_decoration_updated'")?->fetchColumn());

            // Present signed out and signed in; null again once switched off.
            [, $signedOut] = $this->request('fresh', '/api/v1/session.php');
            self::assertSame('celebration', $signedOut['sign_in_decoration'] ?? null);
            [, $signedIn] = $this->request('root', '/api/v1/session.php');
            self::assertSame('celebration', $signedIn['sign_in_decoration'] ?? null);
            self::assertSame(200, $this->request('root', $base . 'update.php', ['sign_in_decoration' => 'off'])[0]);
            [, $off] = $this->request('fresh', '/api/v1/session.php');
            self::assertIsArray($off);
            self::assertArrayHasKey('sign_in_decoration', $off);
            self::assertNull($off['sign_in_decoration']);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            foreach ([$log, $sessions, ...array_values($this->cookies)] as $path) {
                TestScratch::remove($path);
            }
        }
    }

    private function login(string $client, string $username, string $password): void
    {
        $this->request($client, '/api/v1/session.php');
        self::assertSame(200, $this->request($client, '/api/v1/login.php', ['username' => $username, 'password' => $password])[0]);
    }

    /** @param ?array<string,mixed> $payload @return array{int,array<string,mixed>|string,array<string,string>} */
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('decoration-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        $headers = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }
                return strlen($line);
            }]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($withCsrf ? ($this->csrf[$client] ?? '') : '')]]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        if (str_starts_with($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if (isset($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
                $this->csrf[$client] = $decoded['csrf_token'];
            }
            return [$status, $decoded, $headers];
        }
        return [$status, $body, $headers];
    }
}
