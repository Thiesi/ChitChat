<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Config;
use ChitChat\Database;
use Closure;
use PDO;
use PDOException;

/** Optional commit-time wake worker; PostgreSQL remains the durable queue. */
final class WebPushListener
{
    public const CHANNEL = 'chitchat_push';
    public const SAFETY_SECONDS = 15;
    private const STOP_POLL_MILLISECONDS = 500;
    private const MAX_DRAIN = 100;

    private readonly Closure $connect;
    private readonly Closure $dispatcher;

    /**
     * @param (Closure():PDO)|null $connect
     * @param (Closure(PDO):WebPushDispatcher)|null $dispatcher
     */
    public function __construct(Config $config, ?Closure $connect = null, ?Closure $dispatcher = null)
    {
        $this->connect = $connect ?? static fn (): PDO => Database::connect($config, 5);
        $this->dispatcher = $dispatcher ?? static fn (PDO $pdo): WebPushDispatcher => new WebPushDispatcher($pdo, $config);
    }

    /**
     * A sweep processes at most one new source and one device attempt, bounded
     * by transport timeouts and connection-level SQL/lock timeouts. Due backlog
     * drains immediately; idle workers sweep at least every safety interval.
     *
     * @param Closure():bool $stopping
     * @param Closure(string,array<string,int>):void $report
     */
    public function run(Closure $stopping, Closure $report): void
    {
        $backoff = 1;
        while (!$this->stopping($stopping)) {
            $pdo = null;
            $worker = null;
            try {
                $pdo = ($this->connect)();
                $pdo->exec("SET statement_timeout = '5s'");
                $pdo->exec("SET lock_timeout = '1s'");
                $pdo->exec('LISTEN ' . self::CHANNEL);
                $worker = ($this->dispatcher)($pdo);
                $reason = 'startup';
                while (!$this->stopping($stopping)) {
                    $summary = $worker->dispatch(1, false);
                    $backoff = 1;
                    $report($reason, $summary);
                    if ($this->stopping($stopping)) {
                        break;
                    }
                    if (($summary['processed'] ?? 0) > 0 || ($summary['has_due_work'] ?? 0) > 0) {
                        $reason = 'backlog';
                        continue;
                    }
                    $nextSweep = hrtime(true) + self::SAFETY_SECONDS * 1_000_000_000;
                    $reason = 'safety';
                    while (!$this->stopping($stopping) && hrtime(true) < $nextSweep) {
                        if ($this->notified($pdo, self::STOP_POLL_MILLISECONDS)) {
                            // Coalesce a bounded number of empty wake messages.
                            for ($count = 0; $count < self::MAX_DRAIN; $count++) {
                                if (!$this->notified($pdo, 0)) {
                                    break;
                                }
                            }
                            $reason = 'notify';
                            break;
                        }
                    }
                }
            } catch (PDOException $exception) {
                if ($this->stopping($stopping)) {
                    break;
                }
                $class = substr((string) $exception->getCode(), 0, 2);
                if (!in_array($class, ['08', '40', '53', '55', '57', 'HY'], true)) {
                    throw $exception; // Bad credentials/schema need an operator, not a tight reconnect loop.
                }
                $report('reconnect', ['retry_in_seconds' => $backoff]);
                // Release the old connection and dispatcher before waiting/reconnecting.
                $worker = null;
                $pdo = null;
                $until = hrtime(true) + $backoff * 1_000_000_000;
                while (!$this->stopping($stopping) && hrtime(true) < $until) {
                    usleep(100_000);
                }
                $backoff = min(30, $backoff * 2);
            } finally {
                if ($pdo !== null) {
                    try {
                        $pdo->exec('UNLISTEN ' . self::CHANNEL);
                    } catch (PDOException) {
                        // A lost connection already discarded its LISTEN registration.
                    }
                }
                $worker = null;
                $pdo = null;
            }
        }
    }

    /**
     * Stop flags may change asynchronously through a signal handler.
     * @param Closure():bool $probe
     * @phpstan-impure
     */
    private function stopping(Closure $probe): bool
    {
        return $probe();
    }

    /** @phpstan-impure */
    private function notified(PDO $pdo, int $timeoutMilliseconds): bool
    {
        // PDO's driver-specific API returns false on timeout; its generic stub omits that case.
        /** @var array<string,mixed>|false $wake */
        $wake = $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, $timeoutMilliseconds);
        return $wake !== false;
    }
}
