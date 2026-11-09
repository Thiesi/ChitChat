<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Realtime\EventRepository;
use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestSession;
use ChitChat\Tests\Support\TestScratch;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class LoginSessionSseTest extends DatabaseTestCase
{
    public function testRevocationClosesOnlyItsConnectionBeforeTheNextEventBatch(): void
    {
        $session = new TestSession();
        $session->start();
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $logins = new LoginSessionRepository($this->pdo);
        $directory = session_save_path();
        $holders = [];
        $processes = [];
        $logs = [];
        $handles = [];
        $bodies = ['', ''];
        $multi = curl_multi_init();
        try {
            for ($index = 0; $index < 2; $index++) {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_id(bin2hex(random_bytes(16)));
                    session_start();
                }
                $_SESSION = [];
                $issued = $logins->issue($member);
                SessionManager::login($member, $issued);
                $holders[] = $issued;
                $cookie = $this->config->sessionName . '=' . session_id();
                session_write_close();
                $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
                self::assertIsResource($socket, $message);
                $address = stream_socket_get_name($socket, false);
                self::assertIsString($address);
                fclose($socket);
                $log = TestScratch::file('login-sse-' . $index);
                $logs[] = $log;
                $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $directory, '-S', $address,
                    '-t', dirname(__DIR__, 2) . '/public'], [0 => ['pipe','r'],1 => ['file',$log,'a'],2 => ['file',$log,'a']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
                PhpHttpFixture::waitUntilReady($process, $address, $log);
                $curl = curl_init('http://' . $address . '/api/v1/events/stream.php');
                self::assertNotFalse($curl);
                curl_setopt_array($curl, [CURLOPT_COOKIE => $cookie, CURLOPT_TIMEOUT => 8,
                    CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use (&$bodies, $index): int {
                        $bodies[$index] .= $bytes;
                        return strlen($bytes);
                    }]);
                curl_multi_add_handle($multi, $curl);
                $handles[] = $curl;
            }
            $pump = static function (callable $done, float $seconds) use ($multi): void {
                $deadline = microtime(true) + $seconds;
                do {
                    curl_multi_exec($multi, $running);
                    if ($done()) return;
                    curl_multi_select($multi, 0.05);
                } while (microtime(true) < $deadline);
            };
            $pump(static function () use (&$bodies): bool {
                return str_contains($bodies[0], 'retry: 1000') && str_contains($bodies[1], 'retry: 1000');
            }, 5);
            self::assertStringContainsString('retry: 1000', $bodies[0]);
            self::assertStringContainsString('retry: 1000', $bodies[1]);
            self::assertSame(1, $logins->revoke($member, $holders[1]->id, hash('sha256', $holders[1]->token), $holders[0]->id, '127.0.0.1'));
            // The event is visible to this user. The revoked connection must not fetch it.
            (new EventRepository($this->pdo))->publish('global_broadcast', ['message' => 'after-login-revocation'], targetUserId: $member->id);
            $pump(static function () use (&$bodies): bool {
                return str_contains($bodies[0], '"reason":"session_revoked"')
                    && str_contains($bodies[1], 'after-login-revocation');
            }, 5);
            self::assertStringContainsString('event: forced_logout', $bodies[0]);
            self::assertStringContainsString('"reason":"session_revoked"', $bodies[0]);
            self::assertStringNotContainsString('after-login-revocation', $bodies[0]);
            self::assertStringNotContainsString('forced_logout', $bodies[1]);
            self::assertStringContainsString('after-login-revocation', $bodies[1]);
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM realtime_events WHERE event_type = 'forced_logout'")->fetchColumn());
        } finally {
            foreach ($handles as $handle) curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                proc_close($process);
            }
            foreach ($logs as $log) TestScratch::remove($log);
            $session->stop();
        }
    }
}
