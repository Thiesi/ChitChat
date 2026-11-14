<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TemporaryPaths;

/**
 * #360: editing or deleting a direct message, through a real PHP web server. Someone outside
 * the conversation gets the same 404 for every DM ID, missing, active or deleted, so IDs don't
 * reveal which messages exist or were deleted. The sender and recipient answers are unchanged.
 */
final class DirectMessageMutationHttpTest extends DatabaseTestCase
{
    use TemporaryPaths;

    private string $baseUrl;
    /** @var array<string,string> */
    private array $cookies = [];
    /** @var array<string,string> */
    private array $csrf = [];

    public function testOutsidersGetTheSame404ForEveryDirectMessageId(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $sender = $auth->register('Sender', 'a very secure password', '127.0.0.1');
        $recipient = $auth->register('Recipient', 'another secure password', '127.0.0.2');
        $auth->register('Outsider', 'a third safe password', '127.0.0.3');
        $messages = new DirectMessageService($this->pdo);
        $active = (int) $messages->send($sender, $recipient->id, 'Still here')['id'];
        $deleted = (int) $messages->send($sender, $recipient->id, 'Soon gone')['id'];
        (new DirectMessageMutationService($this->pdo))->deleteOwn($sender, $deleted, '127.0.0.1');
        $missing = (int) $this->pdo->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM direct_messages')->fetchColumn();
        $process = $this->startServer();
        try {
            $this->login('sender', 'Sender', 'a very secure password');
            $this->login('recipient', 'Recipient', 'another secure password');
            $this->login('outsider', 'Outsider', 'a third safe password');
            $rows = fn (): array => $this->pdo->query('SELECT id, body, edited_at, deleted_at FROM direct_messages ORDER BY id')->fetchAll();
            $rowsBefore = $rows();
            $auditBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
            $edit = static fn (int $id): array => ['message_id' => $id, 'body' => 'Changed by someone'];
            $delete = static fn (int $id): array => ['message_id' => $id];

            foreach (['edit' => $edit, 'delete' => $delete] as $name => $payload) {
                $path = '/api/v1/direct-messages/' . $name . '.php';
                $notFound = $this->request('outsider', $path, $payload($missing));
                self::assertSame(404, $notFound['status'], $name . ': ' . $notFound['body']);
                self::assertSame(['code' => 'message_not_found', 'message' => 'Direct message not found.'], $this->json($notFound)['error'], $name);
                foreach (['active' => $active, 'deleted' => $deleted] as $kind => $id) {
                    $this->assertSameAnswer($notFound, $this->request('outsider', $path, $payload($id)), $name . ' ' . $kind);
                }

                // The recipient may know the message exists and whether it was deleted: unchanged.
                $this->assertError($this->request('recipient', $path, $payload($active)), 403, 'message_author_required', $name . ' recipient active');
                $this->assertError($this->request('recipient', $path, $payload($deleted)), 409, 'message_already_deleted', $name . ' recipient deleted');
                $this->assertError($this->request('sender', $path, $payload($deleted)), 409, 'message_already_deleted', $name . ' sender deleted');
                $this->assertError($this->request('recipient', $path, $payload($missing)), 404, 'message_not_found', $name . ' recipient missing');
            }
            self::assertSame($rowsBefore, $rows(), 'Refused attempts change nothing.');
            self::assertSame($auditBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(), 'Refused attempts write no audit rows.');

            // The sender can still edit and then delete.
            $edited = $this->request('sender', '/api/v1/direct-messages/edit.php', $edit($active));
            self::assertSame(200, $edited['status'], $edited['body']);
            self::assertSame('Changed by someone', $this->json($edited)['message']['body']);
            $gone = $this->request('sender', '/api/v1/direct-messages/delete.php', $delete($active));
            self::assertSame(200, $gone['status'], $gone['body']);
            self::assertTrue($this->json($gone)['message']['deleted']);
            // Now deleted: still the plain 404 for the outsider.
            $this->assertSameAnswer(
                $this->request('outsider', '/api/v1/direct-messages/delete.php', $delete($missing)),
                $this->request('outsider', '/api/v1/direct-messages/delete.php', $delete($active)),
                'delete, after the sender deleted it',
            );
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            $this->removeTemporaryPaths();
        }
    }

    /** @param array{status:int,body:string,headers:array<string,string>} $response */
    private function assertError(array $response, int $status, string $code, string $case): void
    {
        self::assertSame($status, $response['status'], $case . ': ' . $response['body']);
        self::assertSame($code, $this->json($response)['error']['code'], $case);
    }

    /**
     * Identical status, body and the headers that describe the answer.
     *
     * @param array{status:int,body:string,headers:array<string,string>} $expected
     * @param array{status:int,body:string,headers:array<string,string>} $actual
     */
    private function assertSameAnswer(array $expected, array $actual, string $case): void
    {
        self::assertSame($expected['status'], $actual['status'], $case . ': ' . $actual['body']);
        self::assertSame($expected['body'], $actual['body'], $case);
        foreach (['content-type', 'cache-control', 'content-length', 'retry-after'] as $name) {
            self::assertSame($expected['headers'][$name] ?? null, $actual['headers'][$name] ?? null, $case . ': ' . $name);
        }
    }

    /** @return resource */
    private function startServer()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $this->baseUrl = 'http://' . $address;
        $log = $this->temporaryFile();
        $sessions = $this->temporaryDirectory(true);
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', dirname(__DIR__, 2) . '/public'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
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
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function request(string $client, string $path, ?array $payload = null): array
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
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode((object) $payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . ($this->csrf[$client] ?? '')]]);
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
