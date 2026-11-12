<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestSession;
use ChitChat\Tests\Support\TestScratch;

/** stream.php sends a bare id line past invisible events, and a reconnect resumes from it (#281). */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class StreamCursorSseTest extends DatabaseTestCase
{
    public function testTheStreamRemembersASkippedPositionAcrossReconnects(): void
    {
        $session = new TestSession();
        $session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $lounge = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $other = $rooms->create($owner, 'other', 'Other', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $lounge->id, '127.0.0.2');
        $this->pdo->exec(<<<SQL
INSERT INTO realtime_events (event_type, room_id, payload)
SELECT 'room_broadcast', {$other->id}, jsonb_build_object('n', n) FROM generate_series(1, 1500) n
SQL);
        $settled = (int) $this->pdo->query('SELECT last_value FROM realtime_events_id_seq')?->fetchColumn();

        $_SESSION = [];
        SessionManager::login($member, (new LoginSessionRepository($this->pdo))->issue($member));
        $cookie = $this->config->sessionName . '=' . session_id();
        session_write_close();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $log = TestScratch::file('stream-cursor-sse');
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . session_save_path(), '-S', $address,
            '-t', dirname(__DIR__, 2) . '/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $first = $this->read($address, $cookie, null, static fn (string $body): bool => str_contains($body, "id: {$settled}\n\n"));
            self::assertStringContainsString("id: {$settled}\n\n", $first, 'A bare id line moves the cursor to the settled horizon.');
            self::assertStringNotContainsString('event: room_broadcast', $first, 'Nothing from the other room is sent.');

            $sent = (new MessageService($this->pdo))->send($owner, $lounge->id, 'After the reconnect');
            $second = $this->read($address, $cookie, $settled, static fn (string $body): bool => str_contains($body, 'After the reconnect'));
            self::assertStringContainsString('After the reconnect', $second);
            self::assertStringContainsString('"id":' . $sent['id'], $second);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            TestScratch::remove($log);
        }
    }

    /** Reads the stream until $done says so, or for a few seconds. */
    private function read(string $address, string $cookie, ?int $lastEventId, callable $done): string
    {
        $body = '';
        $curl = curl_init('http://' . $address . '/api/v1/events/stream.php');
        self::assertNotFalse($curl);
        $headers = $lastEventId === null ? [] : ['Last-Event-ID: ' . $lastEventId];
        curl_setopt_array($curl, [CURLOPT_COOKIE => $cookie, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use (&$body, $done): int {
                $body .= $bytes;
                return $done($body) ? 0 : strlen($bytes);
            }]);
        curl_exec($curl);
        unset($curl);

        return $body;
    }
}
