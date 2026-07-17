<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Backup\BackupException;
use ChitChat\Backup\CliArguments;
use PHPUnit\Framework\TestCase;

final class CliArgumentsTest extends TestCase
{
    public function testParsesSeparatedAndInlineValuesWithFlags(): void
    {
        $options = CliArguments::parse([
            'command',
            '--backup',
            '/srv/backups/one',
            '--database=restore_db',
            '--json',
        ], [
            'backup' => 'value',
            'database' => 'value',
            'json' => 'flag',
        ]);

        self::assertSame('/srv/backups/one', CliArguments::string($options, 'backup'));
        self::assertSame('restore_db', CliArguments::string($options, 'database'));
        self::assertTrue(CliArguments::flag($options, 'json'));
        self::assertFalse(CliArguments::flag($options, 'missing'));
    }

    public function testRejectsUnknownOrRepeatedOptions(): void
    {
        $this->expectException(BackupException::class);
        CliArguments::parse(['command', '--json', '--json'], ['json' => 'flag']);
    }

    public function testRejectsMissingValue(): void
    {
        $this->expectException(BackupException::class);
        CliArguments::parse(['command', '--backup'], ['backup' => 'value']);
    }
}
