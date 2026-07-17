<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Config;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'APP_DEBUG',
            'DB_PORT',
            'PRESENCE_LEASE_SECONDS',
            'INACTIVITY_WARNING_SECONDS',
            'ATTACHMENT_STORAGE_PATH',
            'ATTACHMENT_MAX_BYTES',
            'DM_ADMIN_INSPECTION_ENABLED',
            'DM_ADMIN_INSPECTION_ROLE',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testDefaultsAreUsable(): void
    {
        $config = Config::fromEnvironment();

        self::assertSame('ChitChat', $config->applicationName);
        self::assertSame(5432, $config->databasePort);
        self::assertSame(45, $config->presenceLeaseSeconds);
        self::assertSame(60, $config->inactivityWarningSeconds);
        self::assertSame(10_485_760, $config->attachmentMaxBytes);
        self::assertStringEndsWith('/var/uploads', str_replace('\\', '/', $config->attachmentStoragePath));
        self::assertTrue($config->directMessageInspectionEnabled);
        self::assertSame('super_admin', $config->directMessageInspectionRole);
    }

    public function testBooleanEnvironmentValuesAreParsed(): void
    {
        putenv('APP_DEBUG=yes');
        putenv('DM_ADMIN_INSPECTION_ENABLED=no');

        $config = Config::fromEnvironment();
        self::assertTrue($config->debug);
        self::assertFalse($config->directMessageInspectionEnabled);
    }

    public function testPresenceLeaseRangeIsValidated(): void
    {
        putenv('PRESENCE_LEASE_SECONDS=10');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PRESENCE_LEASE_SECONDS');
        Config::fromEnvironment();
    }

    public function testAttachmentSizeRangeIsValidated(): void
    {
        putenv('ATTACHMENT_MAX_BYTES=100');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ATTACHMENT_MAX_BYTES');
        Config::fromEnvironment();
    }

    public function testAttachmentStorageMustBeAbsoluteAndOutsidePublicRoot(): void
    {
        putenv('ATTACHMENT_STORAGE_PATH=relative/uploads');
        try {
            Config::fromEnvironment();
            self::fail('Expected relative attachment storage rejection.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('absolute path', $exception->getMessage());
        }

        putenv('ATTACHMENT_STORAGE_PATH=' . dirname(__DIR__, 2) . '/public/uploads');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the public web root');
        Config::fromEnvironment();
    }

    public function testDirectMessageInspectionRoleIsValidated(): void
    {
        putenv('DM_ADMIN_INSPECTION_ROLE=chat_admin');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DM_ADMIN_INSPECTION_ROLE');
        Config::fromEnvironment();
    }
}
