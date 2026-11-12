<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

/** rooms/join.php returns the read position the join started, as the room list reports it (#268). */
final class RoomJoinHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testTheJoinResponseCarriesTheRoomListReadFields(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', 'a very secure password', '127.0.0.1');
        $auth->register('Member', 'another secure password', '127.0.0.2');
        $room = (new RoomService($this->pdo))->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $newest = (new MessageService($this->pdo))->send($owner, $room->id, 'Before you came');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('room-join-http-log');
        $sessions = TestScratch::directory('room-join-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $this->request('member', '/api/v1/session.php');
            self::assertSame(200, $this->request('member', '/api/v1/login.php', ['username' => 'Member', 'password' => 'another secure password'])[0]);

            [$status, $joined] = $this->request('member', '/api/v1/rooms/join.php', ['room_id' => $room->id]);
            self::assertSame(200, $status);
            self::assertIsArray($joined['room'] ?? null);
            self::assertSame($room->id, $joined['room']['id']);
            self::assertSame('member', $joined['room']['member_role']);
            self::assertSame(0, $joined['room']['unread_count']);
            self::assertSame($newest['id'], $joined['room']['last_read_message_id']);
            self::assertSame(0, $joined['room']['unread_mention_count']);
            self::assertSame($newest['id'], $joined['room']['unread_through_message_id'], 'The snapshot covers everything up to the newest message (#279).');
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

    /** @param ?array<string,mixed> $payload @return array{int,array<string,mixed>} */
    private function request(string $client, string $path, ?array $payload = null): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('room-join-cookie-' . $client);
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
