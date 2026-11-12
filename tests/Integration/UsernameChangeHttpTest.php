<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\Username;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class UsernameChangeHttpTest extends DatabaseTestCase
{
    private const PASSWORD = 'Username-Change-Http-Aa1!';

    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testRealHttpRequireChooseAndDiscover(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $moderator = $auth->register('Moderator', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
        $member = $auth->register('Rude.Name', self::PASSWORD, '127.0.0.3');
        $room = (new RoomService($this->pdo))->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        (new RoomService($this->pdo))->join($member, $room->id, '127.0.0.3');
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE, registration_min_fill_seconds = 0, registration_proof_of_work_bits = 0');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('username-http-log');
        $sessions = TestScratch::directory('username-http-sessions');
        $environment = array_replace(getenv(), [
            'RATE_LIMIT_USERNAME_CHANGE_MAX_ATTEMPTS' => '4',
            'RATE_LIMIT_USERNAME_CHANGE_WINDOW_SECONDS' => '60',
        ]);
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $require = ['user_id' => $member->id, 'reason' => 'Offensive name', 'case_id' => null];
            $this->request('anonymous', '/api/v1/session.php');
            self::assertSame(401, $this->request('anonymous', '/api/v1/moderation/require-username-change.php', $require)[0]);
            self::assertSame(401, $this->request('anonymous', '/api/v1/account/username.php', ['username' => 'Kind.Name'])[0]);
            $this->login('moderator', 'Moderator', self::PASSWORD);
            $this->login('member', 'Rude.Name', self::PASSWORD);
            $this->login('root', 'Root', self::PASSWORD);

            [, $session] = $this->request('member', '/api/v1/session.php');
            self::assertIsArray($session);
            self::assertTrue($session['capabilities']['moderated_username_change']);
            self::assertNull($session['user']['username_change']);
            [, $card] = $this->request('moderator', '/api/v1/users/profile.php?user_id=' . $member->id);
            self::assertIsArray($card);
            self::assertTrue($card['profile']['moderation']['everywhere']['can_require_username_change']);
            self::assertFalse($card['profile']['moderation']['everywhere']['username_change_required']);
            [, $plainCard] = $this->request('member', '/api/v1/users/profile.php?user_id=' . $root->id);
            self::assertIsArray($plainCard);
            self::assertNull($plainCard['profile']['moderation']);

            self::assertSame(403, $this->request('moderator', '/api/v1/moderation/require-username-change.php', $require, false)[0], 'CSRF is required.');
            [$status, $refused] = $this->request('member', '/api/v1/moderation/require-username-change.php',
                ['user_id' => $root->id, 'reason' => 'No', 'case_id' => null]);
            self::assertSame([403, 'forbidden'], [$status, $refused['error']['code'] ?? null]);
            [$status, $invalid] = $this->request('moderator', '/api/v1/moderation/require-username-change.php', array_replace($require, ['case_id' => 'x']));
            self::assertSame([400, 'validation_error'], [$status, $invalid['error']['code'] ?? null]);

            [$status, $required] = $this->request('moderator', '/api/v1/moderation/require-username-change.php', $require);
            self::assertSame(200, $status);
            self::assertIsArray($required);
            $placeholder = $required['user']['username'];
            self::assertTrue(Username::isPlaceholder($placeholder));
            self::assertSame(['user' => ['id' => $member->id, 'username' => $placeholder], 'case' => null], $required);
            [$status, $again] = $this->request('moderator', '/api/v1/moderation/require-username-change.php', $require);
            self::assertSame([409, 'username_change_already_required'], [$status, $again['error']['code'] ?? null]);
            [, $card] = $this->request('moderator', '/api/v1/users/profile.php?user_id=' . $member->id);
            self::assertIsArray($card);
            self::assertFalse($card['profile']['moderation']['everywhere']['can_require_username_change']);
            self::assertTrue($card['profile']['moderation']['everywhere']['username_change_required']);

            // The member's existing session keeps reading and learns why writing is refused.
            [, $session] = $this->request('member', '/api/v1/session.php');
            self::assertIsArray($session);
            self::assertSame($placeholder, $session['user']['username']);
            self::assertSame('Offensive name', $session['user']['username_change']['reason']);
            [$status, $send] = $this->request('member', '/api/v1/rooms/send.php', ['room_id' => $room->id, 'body' => 'Hello']);
            self::assertSame([403, 'username_change_required'], [$status, $send['error']['code'] ?? null]);
            self::assertSame(200, $this->request('member', '/api/v1/rooms/messages.php?room_id=' . $room->id)[0]);

            // A guest cannot use the chooser.
            $this->request('guest', '/api/v1/session.php');
            self::assertSame(201, $this->request('guest', '/api/v1/guest/start.php', [])[0]);
            [$status, $guest] = $this->request('guest', '/api/v1/account/username.php', ['username' => 'Guesty']);
            self::assertSame([403, 'guest_not_allowed'], [$status, $guest['error']['code'] ?? null]);

            self::assertSame(403, $this->request('member', '/api/v1/account/username.php', ['username' => 'Kind.Name'], false)[0], 'CSRF is required.');
            [$status, $taken] = $this->request('member', '/api/v1/account/username.php', ['username' => 'rude.name']);
            self::assertSame([409, 'username_taken'], [$status, $taken['error']['code'] ?? null]);
            [$status, $badType] = $this->request('member', '/api/v1/account/username.php', ['username' => 7]);
            self::assertSame([400, 'validation_error'], [$status, $badType['error']['code'] ?? null]);
            [$status, $chosen] = $this->request('member', '/api/v1/account/username.php', ['username' => 'Kind.Name']);
            self::assertSame(200, $status);
            self::assertIsArray($chosen);
            self::assertSame('Kind.Name', $chosen['user']['username']);
            self::assertNull($chosen['user']['username_change']);
            self::assertSame(201, $this->request('member', '/api/v1/rooms/send.php', ['room_id' => $room->id, 'body' => 'Hello'])[0]);
            // Four attempts per minute in this fixture: taken, bad type, chosen, and this one.
            [$status, $fourth] = $this->request('member', '/api/v1/account/username.php', ['username' => 'Third.Name']);
            self::assertSame([409, 'username_change_not_required'], [$status, $fourth['error']['code'] ?? null]);
            [$status, $limited, $headers] = $this->request('member', '/api/v1/account/username.php', ['username' => 'Third.Name']);
            self::assertSame([429, 'rate_limited'], [$status, $limited['error']['code'] ?? null]);
            self::assertArrayHasKey('retry-after', $headers);

            // The reservation period is operable from the settings API.
            [, $settings] = $this->request('root', '/api/v1/admin/settings/get.php');
            self::assertIsArray($settings);
            self::assertSame(365, $settings['settings']['username_reservation_days']);
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
        $cookie = $this->cookies[$client] ??= TestScratch::file('username-cookie-' . $client);
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
