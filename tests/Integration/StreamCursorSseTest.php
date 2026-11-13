<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestSession;
use ChitChat\Tests\Support\TestScratch;

/**
 * stream.php sends a bare id line past invisible events, and a reconnect resumes from it (#281).
 * With resume=settled, a late-committing lower id still arrives, and ids are settled positions (#286).
 */
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
        // The built-in server handles one request at a time and the first stream keeps running
        // until it notices the client left, so the reconnect gets a server of its own.
        $servers = [];
        try {
            $servers[] = $first = $this->server('stream-cursor-sse-1');
            $servers[] = $second = $this->server('stream-cursor-sse-2');
            $before = $this->read($first['address'], $cookie, null, static fn (string $body): bool => str_contains($body, "id: {$settled}\n\n"));
            self::assertStringContainsString("id: {$settled}\n\n", $before, 'A bare id line moves the cursor to the settled horizon.');
            self::assertStringNotContainsString('event: room_broadcast', $before, 'Nothing from the other room is sent.');

            $sent = (new MessageService($this->pdo))->send($owner, $lounge->id, 'After the reconnect');
            $after = $this->read($second['address'], $cookie, $settled, static fn (string $body): bool => str_contains($body, 'After the reconnect'));
            self::assertStringContainsString('After the reconnect', $after);
            self::assertStringContainsString('"id":' . $sent['id'], $after);
            self::assertStringNotContainsString('event: room_broadcast', $after, 'Resumed after the horizon, not from the start.');
        } finally {
            foreach ($servers as $server) {
                if (proc_get_status($server['process'])['running']) {
                    proc_terminate($server['process']);
                }
                proc_close($server['process']);
                TestScratch::remove($server['log']);
            }
        }
    }

    public function testASettledResumeStreamDeliversALateLowerIdAndSendsSettledPositions(): void
    {
        $session = new TestSession();
        $session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $lounge = $rooms->create($owner, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $lounge->id, '127.0.0.2');
        $start = (int) $this->pdo->query('SELECT MAX(id) FROM realtime_events')?->fetchColumn();

        $_SESSION = [];
        SessionManager::login($member, (new LoginSessionRepository($this->pdo))->issue($member));
        $cookie = $this->config->sessionName . '=' . session_id();
        session_write_close();

        $publisher = Database::connect($this->config);
        $publisher->beginTransaction();
        $servers = [];
        try {
            $low = (int) $publisher->query("INSERT INTO realtime_events (event_type, room_id, payload) VALUES ('room_broadcast', {$lounge->id}, '{\"late\":true}'::jsonb) RETURNING id")?->fetchColumn();
            $high = (int) $this->pdo->query("INSERT INTO realtime_events (event_type, room_id, payload) VALUES ('room_broadcast', {$lounge->id}, '{\"early\":true}'::jsonb) RETURNING id")?->fetchColumn();
            $servers[] = $server = $this->server('stream-settled-sse');
            $refused = $this->read($server['address'], $cookie, $start, static fn (string $body): bool => $body !== '', 'resume=other');
            self::assertStringContainsString('validation_error', $refused);

            $servers[] = $stream = $this->server('stream-settled-sse-2');
            $body = $this->read($stream['address'], $cookie, $start, static function (string $body) use ($publisher): bool {
                if ($publisher->inTransaction() && str_contains($body, '"early":true')) {
                    $publisher->commit();
                }
                // Done once a bare id line follows the late event: settled has passed it.
                return str_contains($body, '"late":true')
                    && preg_match("/\nid: \\d+\n\n/", substr($body, (int) strpos($body, '"late":true'))) === 1;
            }, 'resume=settled');

            self::assertSame(1, substr_count($body, '"early":true'));
            self::assertSame(1, substr_count($body, '"late":true'), 'The late lower id arrives once.');
            self::assertLessThan(strpos($body, '"late":true'), strpos($body, '"early":true'));
            self::assertMatchesRegularExpression("/id: {$start}\nevent: room_broadcast\ndata: \\{\"id\":{$high},/", $body,
                'The early event carries the settled position, below the open lower id.');
            preg_match_all('/^id: (\d+)$/m', $body, $ids);
            $positions = array_map('intval', $ids[1]);
            $sorted = $positions;
            sort($sorted);
            self::assertSame($sorted, $positions, 'Positions never go back.');
            self::assertGreaterThanOrEqual($high, max($positions), 'Settled past both once both are sent.');
            self::assertLessThan($low, min($positions));
        } finally {
            if ($publisher->inTransaction()) {
                $publisher->rollBack();
            }
            foreach ($servers as $server) {
                if (proc_get_status($server['process'])['running']) {
                    proc_terminate($server['process']);
                }
                proc_close($server['process']);
                TestScratch::remove($server['log']);
            }
        }
    }

    /** @return array{process:resource, address:string, log:string} */
    private function server(string $label): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $log = TestScratch::file($label);
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . session_save_path(), '-S', $address,
            '-t', dirname(__DIR__, 2) . '/public'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        PhpHttpFixture::waitUntilReady($process, $address, $log);

        return ['process' => $process, 'address' => $address, 'log' => $log];
    }

    /** Reads the stream until $done says so, or for a few seconds. */
    private function read(string $address, string $cookie, ?int $lastEventId, callable $done, string $query = ''): string
    {
        $body = '';
        $curl = curl_init('http://' . $address . '/api/v1/events/stream.php' . ($query === '' ? '' : '?' . $query));
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
