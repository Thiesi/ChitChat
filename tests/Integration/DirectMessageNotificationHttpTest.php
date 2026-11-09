<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class DirectMessageNotificationHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function testOptInIsDiscoverableOptionalAndAtomicThroughTheRealHttpApi(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $sender = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $recipient = $auth->register('Member', 'different secure passphrase', '127.0.0.2');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('dm-alert-http-log');
        $sessions = TestScratch::directory('dm-alert-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            self::assertSame(401, $this->request('member', '/api/v1/push/preferences.php')[0]);
            $this->request('member', '/api/v1/session.php');
            self::assertSame(200, $this->request('member', '/api/v1/login.php',
                ['username' => 'Member', 'password' => 'different secure passphrase'])[0]);
            [$status, $defaults] = $this->request('member', '/api/v1/push/preferences.php');
            self::assertSame(200, $status);
            self::assertFalse($defaults['preferences']['direct_message_push_enabled']);
            self::assertSame([], $defaults['devices'], 'Preferences are available without a browser registration.');
            $input = ['mentioned_push_enabled' => true, 'direct_message_push_enabled' => true,
                'quiet_hours_start' => 22, 'quiet_hours_end' => 7, 'quiet_hours_timezone' => 'UTC'];
            self::assertSame(403, $this->request('member', '/api/v1/push/update-preferences.php', $input, false)[0]);
            [$status, $enabled] = $this->request('member', '/api/v1/push/update-preferences.php', $input);
            self::assertSame(200, $status);
            self::assertTrue($enabled['preferences']['direct_message_push_enabled']);
            foreach ([
                ['mentioned_push_enabled' => false, 'direct_message_push_enabled' => 'yes'],
                ['mentioned_push_enabled' => false, 'direct_message_push_enabled' => null],
                ['mentioned_push_enabled' => false, 'direct_message_push_enabled' => false, 'quiet_hours_start' => 22],
                ['mentioned_push_enabled' => false, 'direct_message_push_enabled' => false, 'quiet_hours_start' => 22,
                    'quiet_hours_end' => 7, 'quiet_hours_timezone' => 'not/a-zone'],
            ] as $invalid) {
                self::assertSame(400, $this->request('member', '/api/v1/push/update-preferences.php', $invalid)[0]);
                self::assertSame($enabled['preferences'], $this->request('member', '/api/v1/push/preferences.php')[1]['preferences']);
            }
            [$status, $legacy] = $this->request('member', '/api/v1/push/update-preferences.php', ['mentioned_push_enabled' => false]);
            self::assertSame(200, $status);
            self::assertTrue($legacy['preferences']['direct_message_push_enabled']);
            self::assertTrue($legacy['preferences']['pinged_push_enabled']);
            self::assertFalse($legacy['preferences']['mentioned_push_enabled']);
            self::assertNull($legacy['preferences']['quiet_hours'], 'The existing omitted-quiet-fields behaviour stays compatible.');
            (new DirectMessageService($this->pdo))->send($sender, $recipient->id, 'secret HTTP-body @Member');
            [$status, $timeline] = $this->request('member', '/api/v1/account/notifications/list.php');
            self::assertSame(200, $status);
            self::assertSame(['direct_message_received', 'mentioned'], array_column($timeline['notifications'], 'kind'));
            self::assertSame('Root sent you a direct message.', $timeline['notifications'][0]['message']);
            self::assertSame('/messages.php?with=' . $sender->id, $timeline['notifications'][0]['link']);
            self::assertStringNotContainsString('secret HTTP-body', json_encode($timeline, JSON_THROW_ON_ERROR));
            self::assertSame(200, $this->request('member', '/api/v1/direct-messages/read.php', ['user_id' => $sender->id])[0]);
            $timeline = $this->request('member', '/api/v1/account/notifications/list.php')[1];
            self::assertTrue($timeline['notifications'][0]['read']);
            self::assertFalse($timeline['notifications'][1]['read'], 'Mention timeline read state remains independent.');
            self::assertStringNotContainsString('secret HTTP-body', (string) file_get_contents($log));
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
        $cookie = $this->cookies[$client] ??= TestScratch::file('dm-alert-cookie-' . $client);
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
