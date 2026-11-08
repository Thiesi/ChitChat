<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AvatarService;
use ChitChat\Auth\AuthService;
use ChitChat\Policy\PolicyService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Upload\AttachmentFileStore;

final class UserReportHttpTest extends DatabaseTestCase
{
    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testRealHttpReportingProtectsEvidenceAndReturnsRetryMetadata(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $subject = $auth->register('Subject', 'another secure password', '127.0.0.2');
        $reporter = $auth->register('Reporter', 'different secure password', '127.0.0.3');
        $moderator = $auth->register('Moderator', 'yet another secure password', '127.0.0.4');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'reports-http', 'Reports HTTP', '', 'public', 0, 0, '127.0.0.1', 'read');
        $rooms->join($subject, $room->id, '127.0.0.1');
        $rooms->join($moderator, $room->id, '127.0.0.1');
        $rooms->setRole($root, $room->id, $moderator->id, 'moderator', '127.0.0.1');
        $storage = TestScratch::directory('person-http-storage');
        $config = $this->configWith(['attachmentStoragePath' => $storage]);
        // Existing stored WebP evidence; the report never receives an upload original.
        $fixture = (string) base64_decode('UklGRsIAAABXRUJQVlA4ILYAAAAwEQCdASoAAQABPlEokkcjoqGhICgAcAoJaW7hdrEe3AAAE9gHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snCgAD+/2yx//3QZZw02///qDP/IM/8gz9Rl6LD4EAAAAAAAAAAAA==', true);
        $files = new AttachmentFileStore($config);
        $key = $files->storeBytes($fixture);
        $statement = $this->pdo->prepare('UPDATE users SET avatar_key = :key, avatar_updated_at = NOW() WHERE id = :id');
        self::assertNotFalse($statement);
        $statement->execute(['key' => $key, 'id' => $subject->id]);
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE, registration_min_fill_seconds = 0, registration_proof_of_work_bits = 0');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = TestScratch::file('person-http-log');
        $sessions = TestScratch::directory('person-http-sessions');
        $environment = array_replace(getenv(), [
            'ATTACHMENT_STORAGE_PATH' => $storage,
            'RATE_LIMIT_USER_REPORT_MAX_ATTEMPTS' => '6',
            'RATE_LIMIT_USER_REPORT_WINDOW_SECONDS' => '60',
        ]);
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $this->request('anonymous', '/api/v1/session.php');
            $payload = ['user_id' => $subject->id, 'category' => 'offensive_profile_picture',
                'details' => 'Private report detail', 'context' => ['room_id' => $room->id]];
            self::assertSame(401, $this->request('anonymous', '/api/v1/reports/user.php', $payload)[0]);
            $this->login('root', 'Root', 'a very secure password');
            $this->login('reporter', 'Reporter', 'different secure password');
            $this->login('moderator', 'Moderator', 'yet another secure password');
            [$status, $profile] = $this->request('reporter', '/api/v1/users/profile.php?user_id=' . $subject->id . '&room_id=' . $room->id);
            self::assertSame(200, $status);
            self::assertIsArray($profile);
            self::assertTrue($profile['profile']['can_report']);
            self::assertFalse($profile['profile']['reported_by_me']);
            [, $self] = $this->request('reporter', '/api/v1/users/profile.php?user_id=' . $reporter->id);
            self::assertIsArray($self);
            self::assertFalse($self['profile']['can_report']);
            self::assertSame(403, $this->request('reporter', '/api/v1/reports/user.php', $payload, false)[0]);
            self::assertSame(400, $this->request('reporter', '/api/v1/reports/user.php', array_replace($payload, ['context' => 'invalid']))[0]);
            $this->request('guest', '/api/v1/session.php');
            self::assertSame(201, $this->request('guest', '/api/v1/guest/start.php', [])[0]);
            [, $guestProfile] = $this->request('guest', '/api/v1/users/profile.php?user_id=' . $subject->id);
            self::assertIsArray($guestProfile);
            self::assertFalse($guestProfile['profile']['can_report']);
            (new PolicyService($this->pdo, $config))->update($root, 'rules', 'hosted', 'Fresh rules', null, false, null, '127.0.0.1');
            // Reporting remains available even while these existing identities owe rules acceptance.
            [$status, $created] = $this->request('reporter', '/api/v1/reports/user.php', $payload);
            self::assertSame(201, $status);
            self::assertIsArray($created);
            $caseId = $created['case']['id'];
            [$status, $duplicate] = $this->request('reporter', '/api/v1/reports/user.php', $payload);
            self::assertSame(409, $status);
            self::assertIsArray($duplicate);
            self::assertSame('already_reported', $duplicate['error']['code']);
            [$status, $global] = $this->request('reporter', '/api/v1/reports/user.php', array_replace($payload, ['context' => null]));
            self::assertSame(201, $status);
            self::assertIsArray($global);
            $globalId = $global['case']['id'];
            [$status, $guestError] = $this->request('guest', '/api/v1/reports/user.php', array_replace($payload, ['context' => null]));
            self::assertSame(403, $status);
            self::assertIsArray($guestError);
            self::assertSame('report_not_allowed', $guestError['error']['code']);
            self::assertSame(201, $this->request('guest', '/api/v1/reports/user.php', $payload)[0]);
            [$status, $queue] = $this->request('moderator', '/api/v1/moderation/cases.php?subject_kind=user');
            self::assertSame(200, $status);
            self::assertIsArray($queue);
            self::assertCount(1, $queue['cases']);
            self::assertSame(2, $queue['cases'][0]['report_count']);
            self::assertSame('user', $queue['cases'][0]['subject_kind']);
            self::assertNull($queue['cases'][0]['message_id']);
            self::assertTrue($queue['cases'][0]['room']['available']);
            self::assertSame(400, $this->request('root', '/api/v1/moderation/cases.php?subject_kind=invalid')[0]);
            self::assertSame(403, $this->request('moderator', '/api/v1/moderation/case.php?case_id=' . $globalId)[0]);
            [$status, $detail] = $this->request('moderator', '/api/v1/moderation/case.php?case_id=' . $caseId);
            self::assertSame(200, $status);
            self::assertIsArray($detail);
            self::assertArrayNotHasKey('evidence_body', $detail['case']['reports'][0]);
            $url = $detail['case']['snapshot']['avatar_url'];
            self::assertIsString($url);
            [$status, $image, $headers] = $this->request('moderator', $url);
            self::assertSame(200, $status);
            self::assertSame($fixture, $image);
            self::assertSame('image/webp', $headers['content-type']);
            self::assertSame('private, no-store', $headers['cache-control']);
            self::assertSame('nosniff', $headers['x-content-type-options']);
            self::assertArrayNotHasKey('etag', $headers);
            self::assertSame(401, $this->request('anonymous', $url)[0]);
            self::assertSame(403, $this->request('reporter', $url)[0]);
            self::assertSame(403, $this->request('moderator', '/api/v1/moderation/case-avatar.php?case_id=' . $globalId)[0]);
            (new AvatarService($this->pdo, $config))->remove($root, $subject->id, '127.0.0.1');
            self::assertFileDoesNotExist($files->pathForKey($key));
            self::assertSame($fixture, $this->request('moderator', $url)[1]);
            [, $changed] = $this->request('moderator', '/api/v1/moderation/case.php?case_id=' . $caseId);
            self::assertIsArray($changed);
            self::assertTrue($changed['case']['avatar_changed_since_report']);
            $rooms->setRole($root, $room->id, $moderator->id, 'member', '127.0.0.1');
            self::assertSame(403, $this->request('moderator', $url)[0]);
            [$status, $noPicture] = $this->request('root', '/api/v1/reports/user.php',
                ['user_id' => $moderator->id, 'category' => 'harassment']);
            self::assertSame(201, $status);
            self::assertIsArray($noPicture);
            self::assertSame(404, $this->request('root', '/api/v1/moderation/case-avatar.php?case_id=' . $noPicture['case']['id'])[0]);
            $limited = false;
            // The first four validated/duplicate attempts used the reporter's six-attempt window.
            for ($attempt = 0; $attempt < 4; $attempt++) {
                [$status, $error, $headers] = $this->request('reporter', '/api/v1/reports/user.php', array_replace($payload, ['category' => 'spam']));
                if ($status === 429) {
                    self::assertIsArray($error);
                    self::assertSame('rate_limited', $error['error']['code']);
                    self::assertIsInt($error['error']['retry_after_seconds']);
                    self::assertGreaterThan(0, $error['error']['retry_after_seconds']);
                    self::assertSame((string) $error['error']['retry_after_seconds'], $headers['retry-after']);
                    $limited = true;
                    break;
                }
                self::assertSame(409, $status);
            }
            self::assertTrue($limited);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            foreach ([$log, $sessions, $storage, ...array_values($this->cookies)] as $path) {
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
        $cookie = $this->cookies[$client] ??= TestScratch::file('person-cookie-' . $client);
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
