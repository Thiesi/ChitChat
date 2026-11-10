<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\Totp\TotpKeyRing;
use ChitChat\Auth\Totp\TotpProtocol;
use ChitChat\Tests\Support\TestTotp;

final class TotpConcurrencyTest extends DatabaseTestCase
{
    public function testTwoConcurrentPurposesCanCommitOneProofOnly(): void
    {
        $key = random_bytes(32);
        $keys = json_encode(['fixture' => base64_encode($key)], JSON_THROW_ON_ERROR);
        $this->config = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson($keys, 'fixture')]);
        $user = (new AuthService($this->pdo, $this->config))->register('Ada', 'a very secure password', '127.0.0.1');
        $seed = TestTotp::seed($this->pdo, $this->config, $user->id);
        $code = TotpProtocol::codeAtStep($seed, intdiv(time(), 30));
        $processes = [];
        $this->pdo->beginTransaction();
        $lock = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE');
        $lock->execute(['id' => $user->id]);
        try {
            foreach (['mfa_login', 'mfa_step_up'] as $i => $purpose) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/totp-proof-worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(['user_id' => $user->id, 'code' => $code, 'purpose' => $purpose,
                    'keys' => $keys, 'active_key' => 'fixture', 'application_name' => 'cc-totp-proof-' . $i,
                    'ip' => '127.0.0.' . ($i + 2)], JSON_THROW_ON_ERROR));
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 10;
            do {
                $this->pdo->query('SELECT pg_stat_clear_snapshot()');
                $waiting = (int) $this->pdo->query("SELECT COUNT(*) FROM pg_stat_activity WHERE application_name LIKE 'cc-totp-proof-%' AND wait_event_type = 'Lock'")->fetchColumn();
                if ($waiting === 2) break;
                usleep(50_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waiting, 'Both proof workers must reach the held account before release.');
            $this->pdo->commit();
            $results = [];
            foreach ($processes as [$process, $pipes]) {
                $output = (string) stream_get_contents($pipes[1]);
                $errors = (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
                self::assertSame('', $errors);
                $results[] = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
            }
            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame([200, 403], $statuses);
            self::assertContains(['status' => 403, 'error_code' => 'invalid_totp'], $results);
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.totp_succeeded'")->fetchColumn());
            self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.totp_failed'")->fetchColumn());
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($processes as [$process, $pipes]) {
                foreach ([1, 2] as $index) if (is_resource($pipes[$index])) fclose($pipes[$index]);
                if (is_resource($process)) {
                    // The held lock is released first; only our own child may be stopped.
                    $state = proc_get_status($process);
                    if ($state['running']) proc_terminate($process);
                    proc_close($process);
                }
            }
        }
    }
}