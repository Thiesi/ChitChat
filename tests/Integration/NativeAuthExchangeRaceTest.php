<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Auth\NativeAuth\NativeAuthTransactions;
use ChitChat\Database;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Tests\Support\TestSession;
use PDO;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class NativeAuthExchangeRaceTest extends DatabaseTestCase
{
    private TestSession $session;
    private PDO $blocker;
    /** @var list<array{process:resource,log:string,pid_file:string}> */
    private array $workers = [];
    /** @var list<string> */
    private array $scratch = [];
    /** @var array<string,mixed> */
    private array $changes;
    /** @var array<string,string> */
    private array $grant;
    private int $userId;
    private string $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $this->userId = $member->id;
        $this->changes = ['applicationOrigin' => 'https://chat.test', 'oidcRedirectOrigin' => 'https://chat.test',
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']],
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]]];
        $config = $this->configWith($this->changes);
        $rows = new NativeAuthRepository($this->pdo);
        $rows->statement("INSERT INTO user_identities(user_id,provider,subject) VALUES (:id,'google','private-subject')", ['id' => $member->id]);
        $state = NativeConfig::token();
        $start = (new NativeAuthTransactions($this->pdo, $config))->start(['client_id' => 'de.networkxxiii.chitchat',
            'provider' => 'google', 'purpose' => 'login', 'state' => $state,
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256'], null, '192.0.2.1');
        $rows->stageProof($start['transaction_id'], 'consent', $member->id, $member->sessionVersion,
            ['subject' => 'private-subject', 'auth_time' => null, 'started_at' => time()], null);
        $code = NativeConfig::token();
        $rows->statement("UPDATE native_auth_transactions SET phase = 'staged', code_hash = :hash, code_expires_at = LEAST(expires_at, clock_timestamp() + interval '90 seconds') WHERE id = :id",
            ['id' => $start['transaction_id'], 'hash' => hash('sha256', $code)]);
        $this->grant = ['transaction_id' => $start['transaction_id'], 'code' => $code, 'state' => $state,
            'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'];
        $this->blocker = Database::connect($config);
        $this->sessions = TestScratch::directory('native-race-session');
        $this->scratch[] = $this->sessions;
    }

    protected function tearDown(): void
    {
        if (isset($this->blocker) && $this->blocker->inTransaction()) $this->blocker->rollBack();
        foreach ($this->workers as $worker) {
            if (proc_get_status($worker['process'])['running']) proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
        foreach (array_reverse($this->scratch) as $path) TestScratch::remove($path);
        $this->session->stop();
        parent::tearDown();
    }

    private function hold(string $table, int|string $id): void
    {
        $this->blocker->beginTransaction();
        $statement = $this->blocker->prepare('SELECT id FROM ' . $table . ' WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        self::assertNotFalse($statement->fetchColumn());
    }

    private function worker(string $operation = 'exchange'): int
    {
        $fixture = TestScratch::file('native-race-fixture');
        $log = TestScratch::file('native-race-log');
        $pid = TestScratch::file('native-race-pid');
        array_push($this->scratch, $fixture, $log, $pid);
        file_put_contents($fixture, json_encode(['config' => $this->changes, 'grant' => $this->grant,
            'session' => $_SESSION, 'sessions' => $this->sessions, 'pid_file' => $pid, 'operation' => $operation], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/native-exchange-worker.php', $fixture],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__, 2));
        self::assertIsResource($process);
        fclose($pipes[0]);
        $this->workers[] = ['process' => $process, 'log' => $log, 'pid_file' => $pid];
        return array_key_last($this->workers);
    }

    private function waiting(int $worker, string $queryPart): void
    {
        $pid = '';
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $pid = trim((string) file_get_contents($this->workers[$worker]['pid_file']));
            if ($pid !== '') break;
            usleep(50_000);
        } while (hrtime(true) < $deadline);
        self::assertMatchesRegularExpression('/\A[0-9]+\z/', $pid);
        $statement = $this->pdo->prepare("SELECT wait_event_type = 'Lock' AND query LIKE :query FROM pg_stat_activity WHERE pid = :pid");
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $statement->execute(['pid' => (int) $pid, 'query' => '%' . $queryPart . '%']);
            $waiting = $statement->fetchColumn();
            if ($waiting) break;
            usleep(50_000);
        } while (hrtime(true) < $deadline);
        self::assertTrue((bool) $waiting, 'Worker did not reach the intended lock boundary.');
    }

    /** @return array<string,mixed> */
    private function workerResult(int $worker): array
    {
        $process = $this->workers[$worker]['process'];
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $running = proc_get_status($process)['running'];
            if (!$running) break;
            usleep(50_000);
        } while (hrtime(true) < $deadline);
        self::assertFalse($running, 'Worker did not finish.');
        $raw = (string) file_get_contents($this->workers[$worker]['log']);
        $result = json_decode($raw, true);
        self::assertIsArray($result, $raw);
        return $result;
    }

    public function testConcurrentExchangesCommitOnlyOnePurposeAndNeverReplayAuthentication(): void
    {
        $this->hold('native_auth_transactions', $this->grant['transaction_id']);
        $first = $this->worker();
        $this->waiting($first, 'native_auth_transactions');
        $second = $this->worker();
        $this->waiting($second, 'system_settings');
        $this->blocker->commit();
        self::assertSame(['outcome' => 'completed', 'user_id' => $this->userId], $this->workerResult($first));
        self::assertSame(['error_code' => 'already_used'], $this->workerResult($second));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE successful')->fetchColumn());
        self::assertArrayNotHasKey('auth', $_SESSION, 'A fixture with the original binding cannot inherit a worker response.');
    }

    public function testCancellationCanWinWhileAnExchangeWaitsForTheAccountWithoutTakingItsLock(): void
    {
        $this->hold('users', $this->userId);
        $exchange = $this->worker();
        $this->waiting($exchange, 'users');
        $cancelled = (new NativeAuthTransactions($this->pdo, $this->configWith($this->changes)))->cancel($this->grant['transaction_id'], '192.0.2.1');
        self::assertSame(['status' => 'cancelled'], $cancelled);
        $this->blocker->commit();
        self::assertSame(['error_code' => 'cancelled'], $this->workerResult($exchange));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_attempts WHERE successful')->fetchColumn());
    }

    public function testExchangeWinningTheRowPreventsALaterQueuedCancellationFromClaimingNothingChanged(): void
    {
        $this->hold('native_auth_transactions', $this->grant['transaction_id']);
        $exchange = $this->worker();
        $this->waiting($exchange, 'native_auth_transactions');
        $cancel = $this->worker('cancel');
        $this->waiting($cancel, 'native_auth_transactions');
        $this->blocker->commit();
        self::assertSame(['outcome' => 'completed', 'user_id' => $this->userId], $this->workerResult($exchange));
        self::assertSame(['error_code' => 'already_used'], $this->workerResult($cancel));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_exchange'")->fetchColumn());
    }
}