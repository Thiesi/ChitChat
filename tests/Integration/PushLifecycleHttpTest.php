<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\PushFixtures;
use ChitChat\Tests\Support\TestScratch;

final class PushLifecycleHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function testAuthenticatedContractRefreshLogoutOwnershipAndRetryMetadataOverHttp(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $actor = $auth->register('Member', 'a very secure password', '127.0.0.1');
        $auth->register('Other', 'different secure passphrase', '127.0.0.2');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('push-http-log');
        $sessions = TestScratch::directory('push-http-sessions');
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['RATE_LIMIT_PUSH_SUBSCRIPTION_MANAGEMENT_MAX_ATTEMPTS'] = '100';
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            foreach (['one' => 'Member', 'two' => 'Member', 'foreign' => 'Other'] as $client => $username) {
                $this->request($client, '/api/v1/session.php');
                self::assertSame(200, $this->request($client, '/api/v1/login.php',
                    ['username' => $username, 'password' => $username === 'Other' ? 'different secure passphrase' : 'a very secure password'])[0]);
            }
            // Public IP literal: registration does no network I/O, nor any real DNS.
            $input = ['endpoint' => 'https://8.8.8.8/device-one', 'p256dh' => PushFixtures::key(), 'auth' => PushFixtures::auth()];
            self::assertSame(403, $this->request('one', '/api/v1/push/subscribe.php', $input, false)[0]);
            [$status, $first] = $this->request('one', '/api/v1/push/subscribe.php', $input);
            self::assertSame(200, $status);
            self::assertTrue($first['subscribed']);
            self::assertIsInt($first['id']);
            self::assertMatchesRegularExpression('/\A[\x21-\x7e]{1,64}\z/', $first['binding']);
            for ($i = 0; $i < 22; ++$i) {
                self::assertSame($first, $this->request('one', '/api/v1/push/subscribe.php', $input)[1]);
            }
            self::assertSame(1, (int) $this->pdo->query("SELECT SUM(attempt_count) FROM request_rate_limits WHERE scope = 'push_subscription_management'")->fetchColumn());
            [$status, $conflict] = $this->request('foreign', '/api/v1/push/subscribe.php', $input);
            self::assertSame(409, $status);
            self::assertSame('push_subscription_conflict', $conflict['error']['code']);
            $input['auth'] = PushFixtures::auth(1);
            [$status, $rotated] = $this->request('one', '/api/v1/push/subscribe.php', $input);
            self::assertSame(200, $status);
            self::assertSame($first['id'], $rotated['id']);
            self::assertNotSame($first['binding'], $rotated['binding']);
            $two = $input;
            $two['endpoint'] = 'https://8.8.8.8/device-two';
            self::assertSame(200, $this->request('two', '/api/v1/push/subscribe.php', $two)[0]);
            [$status, $devices] = $this->request('one', '/api/v1/push/preferences.php');
            self::assertSame(200, $status);
            self::assertCount(2, $devices['devices']);
            self::assertCount(1, array_filter($devices['devices'], static fn (array $device): bool => $device['current_session']));
            foreach ($devices['devices'] as $device) {
                foreach (['endpoint', 'p256dh', 'auth', 'binding', 'session_binding_hash'] as $secret) self::assertArrayNotHasKey($secret, $device);
            }
            // Revoke by ID after keys rotate, without the endpoint or any binding.
            self::assertSame(200, $this->request('one', '/api/v1/push/revoke-device.php', ['id' => $first['id']])[0]);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
            self::assertSame(200, $this->request('one', '/api/v1/push/subscribe.php', $input)[0]);
            self::assertSame(200, $this->request('one', '/api/v1/logout.php', [])[0]);
            self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
            self::assertSame('https://8.8.8.8/device-two', $this->pdo->query('SELECT endpoint FROM push_subscriptions')->fetchColumn());
            self::assertSame(401, $this->request('one', '/api/v1/push/preferences.php')[0]);
            $this->pdo->exec("UPDATE request_rate_limits SET attempt_count = 100 WHERE scope = 'push_subscription_management'");
            [$status, $limited] = $this->request('two', '/api/v1/push/subscribe.php', $input);
            self::assertSame(429, $status);
            self::assertGreaterThan(0, $limited['error']['retry_after_seconds']);
            self::assertSame((string) $limited['error']['retry_after_seconds'], $this->headers['retry-after']);
            // Identical refresh remains available even when the mutation allowance is exhausted.
            self::assertSame(200, $this->request('two', '/api/v1/push/subscribe.php', $two)[0]);
            self::assertSame(200, $this->request('two', '/api/v1/logout.php', [])[0]);
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn());
            $contents = file_get_contents($log);
            self::assertIsString($contents);
            self::assertStringNotContainsString(PushFixtures::auth(), $contents);
            self::assertStringNotContainsString('a very secure password', $contents);
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
