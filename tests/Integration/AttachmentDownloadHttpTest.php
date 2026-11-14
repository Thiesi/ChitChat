<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TemporaryPaths;

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
        $auth = new AuthService($this->pdo, $this->config);
        $sender = $auth->register('Sender', 'a very secure password', '127.0.0.1');
        $recipient = $auth->register('Recipient', 'another secure password', '127.0.0.2');
        $auth->register('Outsider', 'a third safe password', '127.0.0.3');
        $storage = $this->temporaryDirectory(true);
        $source = $this->temporaryFile("Plain notes for the recipient.\n");
        $process = $this->startServer($storage);
        try {
            $this->login('sender', 'Sender', 'a very secure password');
            $this->login('recipient', 'Recipient', 'another secure password');
            $this->login('outsider', 'Outsider', 'a third safe password');
            $created = $this->request('sender', '/api/v1/direct-messages/attachments/upload.php',
                ['recipient_user_id' => (string) $recipient->id, 'file' => new \CURLFile($source, 'text/plain', 'notes.txt')], [], true);
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

            (new DirectMessageMutationService($this->pdo))->deleteOwn($sender, (int) $message['id'], '127.0.0.1');

            foreach (['', '&variant=preview', '&inline=1'] as $query) {
                $this->assertSameAnswer(
                    $this->request('outsider', $download($neverExisted, $query)),
                    $this->request('outsider', $download($attachmentId, $query)),
                    'after deletion' . $query,
                );
            }
            foreach (['sender', 'recipient'] as $participant) {
                $deleted = $this->request($participant, $download($attachmentId));
                self::assertSame(410, $deleted['status'], $participant . ': ' . $deleted['body']);
                self::assertSame('attachment_deleted', $this->json($deleted)['error']['code'], $participant);
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
    private function request(string $client, string $path, ?array $payload = null, array $headers = [], bool $multipart = false): array
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
        if ($headers !== []) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, array_map(
                static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), array_values($headers)));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        if (str_contains($received['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && is_string($decoded['csrf_token'] ?? null)) {
                $this->csrf[$client] = $decoded['csrf_token'];
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $received];
    }
}
