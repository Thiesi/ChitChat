<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Database;
use PDO;

/** Web sessions end an idle transaction after a minute; CLI tools and an operator's PGOPTIONS are left alone (#286). */
final class DatabaseSessionSettingsTest extends DatabaseTestCase
{
    public function testWebConnectionsLimitIdleTransactionsAndCliConnectionsKeepTheServerSetting(): void
    {
        $server = $this->setting($this->pdo, 'idle_in_transaction_session_timeout');
        self::assertSame('1min', $this->setting(Database::connect($this->config, null, true), 'idle_in_transaction_session_timeout'));
        self::assertSame($server, $this->setting(Database::connect($this->config, null, false), 'idle_in_transaction_session_timeout'));
        self::assertSame($server, $this->setting(Database::connect($this->config), 'idle_in_transaction_session_timeout'), 'PHPUnit runs from the CLI.');
    }

    public function testAnOperatorsPgoptionsSurvive(): void
    {
        $previous = getenv('PGOPTIONS');
        putenv('PGOPTIONS=-c application_name=pgoptions-survives');
        try {
            $web = Database::connect($this->config, null, true);
            self::assertSame('pgoptions-survives', $this->setting($web, 'application_name'));
            self::assertSame('1min', $this->setting($web, 'idle_in_transaction_session_timeout'));
        } finally {
            putenv($previous === false ? 'PGOPTIONS' : 'PGOPTIONS=' . $previous);
        }
    }

    private function setting(PDO $pdo, string $name): string
    {
        $statement = $pdo->query('SHOW ' . $name);
        self::assertNotFalse($statement);

        return (string) $statement->fetchColumn();
    }
}
