<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthService;
use ChitChat\Moderation\ReportService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;

final class StewardingDataHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];
    /** @var array<string,string> */
    private array $headers = [];

    public function testRealHttpStaffContractsKeepPaginationValidationAuthorizationAndRedaction(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $mod = $auth->register('AnnMod', 'a very secure password', '127.0.0.2');
        $subject = $auth->register('AnnSubject', 'a very secure password', '127.0.0.3');
        $this->pdo->exec("INSERT INTO user_roles (user_id,role) VALUES ({$mod->id},'chat_admin')");
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'http-room', 'HTTP room', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($mod, $room->id, '127.0.0.2');
        $rooms->join($subject, $room->id, '127.0.0.3');
        $rooms->setRole($root, $room->id, $mod->id, 'moderator', '127.0.0.1');
        $message = (new MessageService($this->pdo))->send($subject, $room->id, 'Private HTTP evidence');
        $case = (new ReportService($this->pdo))->reportRoomMessage($mod, $message['id'], 'spam', 'Private HTTP report', '127.0.0.2');
        (new AuditLogger($this->pdo))->log($root->id, 'test.account_target', 'user', (string) $subject->id, ['safe' => true], '127.0.0.1');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('stewarding-http-log');
        $sessions = TestScratch::directory('stewarding-http-sessions');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            foreach (['admin/users.php', 'admin/audit.php', 'moderation/cases.php'] as $route) {
                self::assertSame(401, $this->request('anonymous', '/api/v1/' . $route)[0]);
            }
            foreach (['root' => 'Root', 'mod' => 'AnnMod', 'member' => 'AnnSubject'] as $client => $name) {
                $this->request($client, '/api/v1/session.php');
                self::assertSame(200, $this->request($client, '/api/v1/login.php',
                    ['username' => $name, 'password' => 'a very secure password'])[0]);
            }
            [$status, $first] = $this->request('root', '/api/v1/admin/users.php?search=Ann&limit=1');
            self::assertSame(200, $status);
            self::assertSame($mod->id, $first['users'][0]['id']);
            self::assertSame(['all' => 2, 'staff' => 1, 'banned' => 0, 'muted' => 0, 'closing' => 0], $first['counts']);
            self::assertIsString($first['next_cursor']);
            $next = $this->request('root', '/api/v1/admin/users.php?search=Ann&limit=1&cursor=' . rawurlencode($first['next_cursor']))[1];
            self::assertSame($subject->id, $next['users'][0]['id']);
            self::assertNull($next['next_cursor']);
            $legacy = $this->request('root', '/api/v1/admin/users.php?after_id=' . $mod->id)[1];
            self::assertSame([$subject->id], array_column($legacy['users'], 'id'));
            $staff = $this->request('root', '/api/v1/admin/users.php?search=Ann&filter=staff')[1];
            self::assertSame([$mod->id], array_column($staff['users'], 'id'));
            self::assertSame($first['counts'], $staff['counts']);
            $signedIn = $this->request('root', '/api/v1/admin/users.php?sort=last_sign_in&limit=1')[1];
            self::assertSame($subject->id, $signedIn['users'][0]['id']);
            foreach (['admin/users.php?filter=invalid', 'admin/users.php?sort=invalid',
                'admin/users.php?cursor=bad!', 'admin/audit.php?user_id=0', 'moderation/cases.php?subject_user_id=0'] as $route) {
                self::assertSame(400, $this->request('root', '/api/v1/' . $route)[0], $route);
            }
            foreach (['mod', 'member'] as $client) {
                foreach (['admin/users.php?filter=staff', 'admin/audit.php?user_id=' . $subject->id] as $route) {
                    self::assertSame(403, $this->request($client, '/api/v1/' . $route)[0]);
                }
            }
            [$status, $audit] = $this->request('root', '/api/v1/admin/audit.php?user_id=' . $subject->id);
            self::assertSame(200, $status);
            self::assertContains('test.account_target', array_column($audit['entries'], 'action'));
            $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('a very secure password', $encoded);
            self::assertStringNotContainsString('Private HTTP', $encoded);
            [$status, $cases] = $this->request('mod', '/api/v1/moderation/cases.php?status=all&subject_user_id=' . $subject->id);
            self::assertSame(200, $status);
            self::assertSame([$case['id']], array_column($cases['cases'], 'id'));
            self::assertSame(['open' => 1, 'in_review' => 0, 'resolved' => 0, 'dismissed' => 0], $cases['counts']);
            self::assertStringNotContainsString('Private HTTP', json_encode($cases, JSON_THROW_ON_ERROR));
            self::assertSame(403, $this->request('member', '/api/v1/moderation/cases.php?subject_user_id=' . $subject->id)[0]);
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
        $cookie = $this->cookies[$client] ??= TestScratch::file('stewarding-cookie-' . $client);
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
