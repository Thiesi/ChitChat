<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use PDO;

/**
 * Guards DatabaseTestCase's cleanup (#163): after setUp no table outside KEPT_TABLES has a row,
 * every sequence starts over, and the kept tables hold only what setUp resets them to.
 */
final class DatabaseCleanupTest extends DatabaseTestCase
{
    public function testSetUpLeavesNoRowAndRestartsEverySequence(): void
    {
        $this->assertCleanState();
    }

    public function testCleanupAfterRealDataMatchesAFreshDatabase(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $room = (new RoomService($this->pdo))->create($root, 'cleanup-room', 'Cleanup room', '', 'private', 0, 0, '127.0.0.1');
        (new MessageService($this->pdo))->send($root, $room->id, 'A message for the cleanup to remove');
        // A passkey fires the only DELETE trigger (bump_mfa_policy_generation) during cleanup.
        $this->pdo->exec(sprintf(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key_cose, algorithm, label) VALUES (%d, decode(repeat('01', 32), 'hex'), decode('a0', 'hex'), -7, 'Cleanup key')",
            $member->id,
        ));
        $this->pdo->exec("SELECT nextval('guest_number_seq')");
        self::assertGreaterThan(0, $this->rowCountOutsideKeptTables());

        $this->setUp();

        $this->assertCleanState();
        // IDs start over, as they did after TRUNCATE … RESTART IDENTITY.
        self::assertSame(1, $auth->register('Again', 'yet another secure password', '127.0.0.4')->id);
    }

    public function testGuestNumbersStartOverToo(): void
    {
        // guest_number_seq belongs to no table, so TRUNCATE never restarted it; the cleanup does.
        self::assertSame(1, (int) $this->pdo->query("SELECT nextval('guest_number_seq')")->fetchColumn());
    }

    private function assertCleanState(): void
    {
        self::assertSame(0, $this->rowCountOutsideKeptTables(), 'A table outside KEPT_TABLES kept rows after setUp.');

        $used = $this->pdo->query(<<<'SQL'
SELECT schemaname || '.' || sequencename
FROM pg_sequences
WHERE schemaname = 'public' AND last_value IS NOT NULL
SQL)->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], $used, 'Sequences that did not start over.');

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM system_settings')->fetchColumn());
        $migrations = glob(dirname(__DIR__, 2) . '/migrations/*.sql');
        self::assertIsArray($migrations);
        self::assertSame(count($migrations), (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM policy_documents WHERE source <> 'none' OR version <> 0")->fetchColumn());
    }

    private function rowCountOutsideKeptTables(): int
    {
        $tables = $this->pdo->query(<<<'SQL'
SELECT c.relname
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
SQL)->fetchAll(PDO::FETCH_COLUMN);
        $total = 0;
        foreach (array_diff($tables, self::KEPT_TABLES) as $table) {
            $total += (int) $this->pdo->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
        }

        return $total;
    }
}
