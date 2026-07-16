<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\Realtime\PingCommand;
use PHPUnit\Framework\TestCase;

final class PingCommandTest extends TestCase
{
    public function testNonPingTextIsIgnored(): void
    {
        self::assertNull(PingCommand::parse('hello'));
        self::assertNull(PingCommand::parse('/me waves'));
    }

    public function testUsernameAndOptionalMessageAreParsed(): void
    {
        self::assertSame(
            ['username' => 'Alice', 'message' => 'please look'],
            PingCommand::parse('/ping Alice please look'),
        );
        self::assertSame(
            ['username' => 'Alice', 'message' => ''],
            PingCommand::parse('/ping Alice'),
        );
    }

    public function testMissingUsernameIsRejected(): void
    {
        $this->expectException(ApiException::class);
        PingCommand::parse('/ping');
    }
}
