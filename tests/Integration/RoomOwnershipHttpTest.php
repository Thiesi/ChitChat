<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

/** rooms/transfer-ownership.php over real HTTP: CSRF, step-up and the response (#273). */
final class RoomOwnershipHttpTest extends DatabaseTestCase
{
    private const PASSWORD = 'Room-Ownership-Http-Aa1!';

    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testTheOwnerTransfersAfterStepUp(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        // The first account is the Super-Administrator; the owner gets a role only to create the room.
        $auth->register('Root', self::PASSWORD, '127.0.0.3');
        $owner = $auth->register('RoomOwner', self::PASSWORD, '127.0.0.1');
        $member = $auth->register('NewOwner', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin')");
        $rooms = new RoomService($this->pdo);
        $creator = (new \ChitChat\Auth\UserRepository($this->pdo))->findAuthenticatedById($owner->id);
        self::assertNotNull($creator);
        $room = $rooms->create($creator, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $rooms->join($member, $room->id, '127.0.0.2');
        $body = ['room_id' => $room->id, 'target_user_id' => $member->id];

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('room-ownership-http-log');
        $sessions = TestScratch::directory('room-ownership-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, getenv());
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            // CSRF comes first on every POST, so an anonymous request is refused before authentication.
            self::assertSame(403, $this->request('anonymous', '/api/v1/rooms/transfer-ownership.php', $body)[0]);

            $this->request('owner', '/api/v1/session.php');
            self::assertSame(200, $this->request('owner', '/api/v1/login.php', ['username' => 'RoomOwner', 'password' => self::PASSWORD])[0]);
            [$status, $refused] = $this->request('owner', '/api/v1/rooms/transfer-ownership.php', $body, false);
            self::assertSame(403, $status, 'A missing CSRF token is refused.');
            [$status, $refused] = $this->request('owner', '/api/v1/rooms/transfer-ownership.php', $body);
            self::assertSame([403, 'step_up_required'], [$status, $refused['error']['code'] ?? null]);
            self::assertSame(200, $this->request('owner', '/api/v1/step-up.php', ['password' => self::PASSWORD])[0]);

            [$status, $transferred] = $this->request('owner', '/api/v1/rooms/transfer-ownership.php', $body);
            self::assertSame(200, $status);
            self::assertSame($room->id, $transferred['room']['id'] ?? null);
            self::assertSame('moderator', $transferred['room']['member_role'] ?? null);
            self::assertSame('owner', (string) $this->pdo->query("SELECT role FROM room_members WHERE room_id = {$room->id} AND user_id = {$member->id}")?->fetchColumn());
            [$status, $again] = $this->request('owner', '/api/v1/rooms/transfer-ownership.php', $body);
            self::assertSame([403, 'forbidden'], [$status, $again['error']['code'] ?? null], 'No longer the owner.');
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
    private function request(string $client, string $path, ?array $payload = null, bool $withCsrf = true): array
    {
        $cookie = $this->cookies[$client] ??= TestScratch::file('room-ownership-cookie-' . $client);
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 10]);
        if ($payload !== null) {
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($withCsrf ? ($this->csrf[$client] ?? '') : '')]]);
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
