<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['APP_DEBUG', 'DB_PORT'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testDefaultsAreUsable(): void
    {
        $config = Config::fromEnvironment();

        self::assertSame('ChitChat', $config->applicationName);
        self::assertSame(5432, $config->databasePort);
    }

    public function testBooleanEnvironmentValuesAreParsed(): void
    {
        putenv('APP_DEBUG=yes');

        self::assertTrue(Config::fromEnvironment()->debug);
    }
}
