<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestImages;
use ChitChat\Upload\AttachmentFileStore;

/**
 * The attachment download endpoints through a real PHP web server: what someone may learn
 * about an attachment they can't see.
 */
final class AttachmentDownloadHttpTest extends DatabaseTestCase
{
    use TemporaryPaths;

    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    /**
     * #356: someone outside a Direct conversation gets the same 404 for a deleted attachment as
     * for one that never existed, so a 410 never tells them it existed. Participants still learn
     * it was deleted.
     */
    public function testOutsidersCannotTellADeletedDirectAttachmentFromOneThatNeverExisted(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD with WebP is unavailable.');
        }
        $auth = new AuthService($this->pdo, $this->config);
        $sender = $auth->register('Sender', 'a very secure password', '127.0.0.1');
        $recipient = $auth->register('Recipient', 'another secure password', '127.0.0.2');
        $auth->register('Outsider', 'a third safe password', '127.0.0.3');
        $storage = $this->temporaryDirectory(true);
        $source = $this->temporaryFile(TestImages::jpeg(6));
        $process = $this->startServer($storage);
        try {
            $this->login('sender', 'Sender', 'a very secure password');
            $this->login('recipient', 'Recipient', 'another secure password');
            $this->login('outsider', 'Outsider', 'a third safe password');
            $created = $this->request('sender', '/api/v1/direct-messages/attachments/upload.php',
                ['recipient_user_id' => (string) $recipient->id, 'file' => new \CURLFile($source, 'image/jpeg', 'notes.jpg')], [], true);
            self::assertSame(201, $created['status'], $created['body']);
            $message = $this->json($created)['message'];
            $attachmentId = (int) $this->pdo->query('SELECT id FROM direct_message_attachments WHERE direct_message_id = ' . (int) $message['id'])->fetchColumn();
            self::assertGreaterThan(0, $attachmentId);
            $neverExisted = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM direct_message_attachments')->fetchColumn();
            $download = static fn (int $id, string $query = ''): string => '/api/v1/direct-messages/attachments/download.php?id=' . $id . $query;

            self::assertSame(200, $this->request('recipient', $download($attachmentId))['status']);
            $missing = $this->request('outsider', $download($neverExisted));
            $this->assertNotFound($missing);
            $this->assertSameAnswer($missing, $this->request('outsider', $download($attachmentId)), 'before deletion');
            // Even the real validator doesn't help an outsider: no 304, the same 404. The recipient
            // gets the 304 with it, so the tag is the real one.
            $sha = (string) $this->pdo->query('SELECT preview_sha256 FROM direct_message_attachments WHERE id = ' . $attachmentId)->fetchColumn();
            self::assertSame(64, strlen($sha));
            self::assertSame(304, $this->request('recipient', $download($attachmentId, '&variant=preview'), null, ['If-None-Match' => '"' . $sha . '"'])['status']);
            foreach (['"' . $sha . '"', 'W/"' . $sha . '"', '*'] as $tag) {
                $this->assertSameAnswer(
                    $this->request('outsider', $download($neverExisted, '&variant=preview'), null, ['If-None-Match' => $tag]),
                    $this->request('outsider', $download($attachmentId, '&variant=preview'), null, ['If-None-Match' => $tag]),
                    'live, If-None-Match ' . $tag,
                );
            }

            (new DirectMessageMutationService($this->pdo))->deleteOwn($sender, (int) $message['id'], '127.0.0.1');

            foreach (['', '&variant=preview', '&inline=1'] as $query) {
                $this->assertSameAnswer(
                    $this->request('outsider', $download($neverExisted, $query)),
                    $this->request('outsider', $download($attachmentId, $query)),
                    'after deletion' . $query,
                );
            }
            foreach (['sender', 'recipient'] as $participant) {
                foreach (['', '&variant=preview', '&inline=1'] as $query) {
                    $deleted = $this->request($participant, $download($attachmentId, $query));
                    self::assertSame(410, $deleted['status'], $participant . $query . ': ' . $deleted['body']);
                    self::assertSame('attachment_deleted', $this->json($deleted)['error']['code'], $participant . $query);
                }
                // A participant asking for an ID that never existed still gets the plain 404.
                $this->assertNotFound($this->request($participant, $download($neverExisted)));
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            $this->removeTemporaryPaths();
        }
    }

    /**
     * #357: HEAD on both download endpoints answers exactly like GET (status and headers) with
     * no body: the same session, visibility (#326, #356), deletion and storage checks, and no
     * audit rows. Other methods, and HEAD on the metadata endpoints, stay 405.
     */
    public function testHeadAnswersExactlyLikeGetWithoutABody(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD with WebP is unavailable.');
        }
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $auth->register('Other', 'a third safe password', '127.0.0.3');
        $room = (new RoomService($this->pdo))->create($root, 'head-private', 'Private files', '', 'private', 0, 0, '127.0.0.1');
        $storage = $this->temporaryDirectory(true);
        $config = $this->configWith(['attachmentStoragePath' => $storage]);
        $source = $this->temporaryFile(TestImages::jpeg(6));
        $process = $this->startServer($storage);
        try {
            $this->login('root', 'Root', 'a very secure password');
            $this->login('member', 'Member', 'another secure password');
            $this->login('other', 'Other', 'a third safe password');
            $roomUpload = $this->request('root', '/api/v1/attachments/upload.php',
                ['room_id' => (string) $room->id, 'file' => new \CURLFile($source, 'image/jpeg', 'photo "one".jpg')], [], true);
            self::assertSame(201, $roomUpload['status'], $roomUpload['body']);
            $roomMessage = $this->json($roomUpload)['message'];
            $roomAttachment = $roomMessage['attachment'];
            $directUpload = $this->request('root', '/api/v1/direct-messages/attachments/upload.php',
                ['recipient_user_id' => (string) $member->id, 'file' => new \CURLFile($source, 'image/jpeg', 'portrait.jpg')], [], true);
            self::assertSame(201, $directUpload['status'], $directUpload['body']);
            $directId = (int) $this->pdo->query('SELECT id FROM direct_message_attachments WHERE direct_message_id = ' . (int) $this->json($directUpload)['message']['id'])->fetchColumn();
            $roomPath = '/api/v1/attachments/download.php?id=' . $roomAttachment['id'];
            $directPath = '/api/v1/direct-messages/attachments/download.php?id=' . $directId;
            $neverExisted = 1_000_000;
            $auditBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

            $roomPreview = $this->assertHeadMatchesGet('root', $roomPath . '&variant=preview', 200);
            $directPreview = $this->assertHeadMatchesGet('member', $directPath . '&variant=preview', 200);
            foreach ([
                ['root', $roomPath, [], 200],
                ['root', $roomPath . '&inline=1', [], 200],
                ['root', $roomPath . '&variant=preview', ['If-None-Match' => $roomPreview['etag']], 304],
                ['root', $roomPath . '&variant=preview', ['If-None-Match' => '"wrong"'], 200],
                ['member', $roomPath, [], 404],
                ['member', $roomPath . '&variant=preview', ['If-None-Match' => $roomPreview['etag']], 404],
                ['root', '/api/v1/attachments/download.php?id=' . $neverExisted, [], 404],
                ['root', '/api/v1/attachments/download.php?id=0', [], 400],
                ['anonymous', $roomPath, [], 401],
                ['member', $directPath, [], 200],
                ['root', $directPath . '&variant=preview', ['If-None-Match' => $directPreview['etag']], 304],
                ['other', $directPath, [], 404],
                ['other', '/api/v1/direct-messages/attachments/download.php?id=' . $neverExisted, [], 404],
                ['anonymous', $directPath, [], 401],
            ] as [$client, $path, $headers, $status]) {
                $this->assertHeadMatchesGet($client, $path, $status, $headers);
            }

            self::assertSame($auditBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'Downloads, GET or HEAD, write no audit rows.');

            // Gone from storage, then deleted: the same 410s, and the same 404 for outsiders.
            $files = new AttachmentFileStore($config);
            $key = $this->pdo->query('SELECT storage_key FROM direct_message_attachments WHERE id = ' . $directId)->fetchColumn();
            self::assertIsString($key);
            $files->remove($key);
            $this->assertHeadMatchesGet('member', $directPath, 410, [], 'attachment_storage_missing');
            $this->assertHeadMatchesGet('other', $directPath, 404);
            (new MessageService($this->pdo))->delete($root, $roomMessage['id'], '127.0.0.1');
            // The deletion itself is audited; what follows must add nothing.
            $auditBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
            $this->assertHeadMatchesGet('root', $roomPath, 410, [], 'attachment_deleted');
            $this->assertHeadMatchesGet('member', $roomPath, 404);

            // HEAD is no body on the wire, not just a client that stops reading.
            foreach (['/api/v1/attachments/download.php?id=' . $neverExisted, $directPath] as $path) {
                self::assertSame('', $this->rawHeadBody($path), $path);
            }
            self::assertSame($auditBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'HEAD writes no audit rows.');

            // Everything else stays as it was.
            foreach (['POST', 'PUT', 'DELETE'] as $method) {
                self::assertSame(405, $this->request('root', $roomPath, null, [], false, $method)['status'], $method);
                self::assertSame(405, $this->request('member', $directPath, null, [], false, $method)['status'], $method);
            }
            self::assertSame(405, $this->request('root', '/api/v1/attachments/metadata.php?room_id=' . $room->id . '&message_ids=' . $roomMessage['id'], null, [], false, 'HEAD')['status']);
            self::assertSame(405, $this->request('member', '/api/v1/direct-messages/attachments/metadata.php?message_ids=1', null, [], false, 'HEAD')['status']);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            $this->removeTemporaryPaths();
        }
    }

    /**
     * GET, then HEAD, on the same request: the same status and headers; HEAD has no body.
     *
     * @param array<string,string> $headers
     * @return array<string,string> the GET response's headers
     */
    private function assertHeadMatchesGet(string $client, string $path, int $status, array $headers = [], ?string $code = null): array
    {
        $get = $this->request($client, $path, null, $headers);
        $head = $this->request($client, $path, null, $headers, false, 'HEAD');
        $case = $client . ' ' . $path . ' ' . json_encode($headers);
        self::assertSame($status, $get['status'], $case . ': ' . $get['body']);
        self::assertSame($get['status'], $head['status'], $case);
        self::assertSame('', $head['body'], $case);
        if ($code !== null) {
            self::assertSame($code, $this->json($get)['error']['code'], $case);
        }
        foreach (['content-type', 'content-length', 'content-disposition', 'cache-control', 'etag',
            'content-security-policy', 'cross-origin-resource-policy', 'x-content-type-options', 'retry-after'] as $name) {
            self::assertSame($get['headers'][$name] ?? null, $head['headers'][$name] ?? null, $case . ': ' . $name);
        }
        return $get['headers'];
    }

    /** The bytes after the headers of a HEAD request, read straight off the socket. */
    private function rawHeadBody(string $path): string
    {
        $address = substr($this->baseUrl, strlen('http://'));
        $socket = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, $errorMessage);
        stream_set_timeout($socket, 10);
        fwrite($socket, 'HEAD ' . $path . " HTTP/1.1\r\nHost: " . $address . "\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        $end = strpos($raw, "\r\n\r\n");
        self::assertNotFalse($end, $raw);
        return substr($raw, $end + 4);
    }

    /** @param array{status:int,body:string,headers:array<string,string>} $response */
    private function assertNotFound(array $response): void
    {
        self::assertSame(404, $response['status'], $response['body']);
        self::assertSame(['code' => 'attachment_not_found', 'message' => 'Attachment not found.'], $this->json($response)['error']);
    }

    /**
     * Identical status, body and the headers that describe the answer.
     *
     * @param array{status:int,body:string,headers:array<string,string>} $expected
     * @param array{status:int,body:string,headers:array<string,string>} $actual
     */
    private function assertSameAnswer(array $expected, array $actual, string $case): void
    {
        $this->assertNotFound($actual);
        self::assertSame($expected['status'], $actual['status'], $case);
        self::assertSame($expected['body'], $actual['body'], $case);
        foreach (['content-type', 'cache-control', 'content-length', 'etag', 'content-disposition'] as $name) {
            self::assertSame($expected['headers'][$name] ?? null, $actual['headers'][$name] ?? null, $case . ': ' . $name);
        }
    }

    /** @return resource */
    private function startServer(string $storage)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = $this->temporaryFile();
        $sessions = $this->temporaryDirectory(true);
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['ATTACHMENT_STORAGE_PATH'] = $storage;
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        PhpHttpFixture::waitUntilReady($process, $address, $log);
        return $process;
    }

    private function login(string $client, string $username, string $password): void
    {
        $this->request($client, '/api/v1/session.php');
        $reply = $this->request($client, '/api/v1/login.php', ['username' => $username, 'password' => $password]);
        self::assertSame(200, $reply['status'], $reply['body']);
    }

    /**
     * @param array{status:int,body:string,headers:array<string,string>} $response
     * @return array<string,mixed>
     */
    private function json(array $response): array
    {
        $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /**
     * @param ?array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function request(string $client, string $path, ?array $payload = null, array $headers = [], bool $multipart = false, string $method = 'GET'): array
    {
        $cookie = $this->cookies[$client] ??= $this->temporaryFile();
        $curl = curl_init($this->baseUrl . $path);
        self::assertNotFalse($curl);
        $received = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 15,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            }]);
        if ($payload !== null) {
            $headers['X-CSRF-Token'] = $this->csrf[$client] ?? '';
            if (!$multipart) {
                $headers['Content-Type'] = 'application/json';
            }
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $multipart ? $payload : json_encode((object) $payload, JSON_THROW_ON_ERROR)]);
        }
        if ($method === 'HEAD') {
            curl_setopt($curl, CURLOPT_NOBODY, true);
        } elseif ($method !== 'GET' && $payload === null) {
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        }
        if ($headers !== []) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers)));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        if ($body !== '' && str_contains($received['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && is_string($decoded['csrf_token'] ?? null)) {
                $this->csrf[$client] = $decoded['csrf_token'];
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $received];
    }
}
