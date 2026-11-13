<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

/**
 * Endpoint-level spot checks for #326: through the real routes (CSRF, parsing, rate limits and
 * all), an invisible private room answers byte-for-byte like a missing one, for a room-ID route
 * and a message-ID route.
 */
final class RoomVisibilityHttpTest extends DatabaseTestCase
{
    private const PASSWORD = 'Room-Visibility-Http-Aa1!';

    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testAnInvisibleRoomAndAMissingOneGetTheSameResponseBody(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', self::PASSWORD, '127.0.0.1');
        $auth->register('Outsider', 'An outside caller passphrase 1!', '127.0.0.2');
        $private = (new RoomService($this->pdo))->create($admin, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $message = (new MessageService($this->pdo))->send($admin, $private->id, 'Members only');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('room-visibility-http-log');
        $sessions = TestScratch::directory('room-visibility-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $this->request('outsider', '/api/v1/session.php');
            self::assertSame(200, $this->request('outsider', '/api/v1/login.php', ['username' => 'Outsider', 'password' => 'An outside caller passphrase 1!'])[0]);

            // A room-ID route.
            $hidden = $this->request('outsider', '/api/v1/rooms/messages.php?room_id=' . $private->id);
            $missing = $this->request('outsider', '/api/v1/rooms/messages.php?room_id=999999');
            self::assertSame(404, $hidden[0]);
            self::assertSame($missing, $hidden, 'The same status and body as for a missing room.');
            self::assertSame('room_not_found', $hidden[1]['error']['code'] ?? null);

            // A message-ID route.
            $hidden = $this->request('outsider', '/api/v1/rooms/react.php', ['message_id' => (int) $message['id'], 'emoji' => '👍']);
            $missing = $this->request('outsider', '/api/v1/rooms/react.php', ['message_id' => 999999, 'emoji' => '👍']);
            self::assertSame(404, $hidden[0]);
            self::assertSame($missing, $hidden, 'The same status and body as for a missing message.');
            self::assertSame('message_not_found', $hidden[1]['error']['code'] ?? null);
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

    /** @param array<string,mixed> $payload @return array{int,array<string,mixed>} */
    private function request(string $client, string $path, ?array $payload = null): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('room-visibility-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($this->csrf[$client] ?? '')]]);
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        if (isset($decoded['csrf_token']) && is_string($decoded['csrf_token'])) {
            $this->csrf[$client] = $decoded['csrf_token'];
        }

        return [$status, $decoded];
    }
}
