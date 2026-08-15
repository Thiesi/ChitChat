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
            'APP_VERSION',
            'APP_DEBUG',
            'DB_PORT',
            'LOGIN_MAX_ATTEMPTS',
            'LOGIN_LOCK_MINUTES',
            'RATE_LIMIT_LOGIN_MAX_ATTEMPTS',
            'RATE_LIMIT_LOGIN_WINDOW_SECONDS',
            'RATE_LIMIT_ROOM_SEND_MAX_ATTEMPTS',
            'RATE_LIMIT_ROOM_SEND_WINDOW_SECONDS',
            'PRESENCE_LEASE_SECONDS',
            'INACTIVITY_WARNING_SECONDS',
            'SSE_CONNECTION_LEASE_SECONDS',
            'METRICS_BEARER_TOKEN',
            'MAINTENANCE_MAX_AGE_HOURS',
            'PRIVILEGED_STEP_UP_MAX_AGE_SECONDS',
            'ATTACHMENT_STORAGE_PATH',
            'ATTACHMENT_MAX_BYTES',
            'DM_ADMIN_INSPECTION_ENABLED',
            'DM_ADMIN_INSPECTION_ROLE',
            'MESSAGE_REVISION_REVIEW_ENABLED',
            'MESSAGE_REVISION_REVIEW_ROLE',
            'WEB_PUSH_VAPID_PUBLIC_KEY',
            'WEB_PUSH_VAPID_PRIVATE_KEY',
            'WEB_PUSH_VAPID_SUBJECT',
        ] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testDefaultsAreUsable(): void
    {
        putenv('APP_VERSION');
        unset($_ENV['APP_VERSION'], $_SERVER['APP_VERSION']);
        $config = Config::fromEnvironment();

        self::assertSame('ChitChat', $config->applicationName);
        self::assertSame('1.3.0', $config->applicationVersion);
        self::assertSame(5432, $config->databasePort);
        self::assertSame(45, $config->presenceLeaseSeconds);
        self::assertSame(60, $config->inactivityWarningSeconds);
        self::assertSame(40, $config->sseConnectionLeaseSeconds);
        self::assertSame('', $config->metricsBearerToken);
        self::assertSame(26, $config->maintenanceMaxAgeHours);
        self::assertSame(600, $config->privilegedStepUpMaxAgeSeconds);
        self::assertSame(10_485_760, $config->attachmentMaxBytes);
        self::assertStringEndsWith('/var/uploads', str_replace('\\', '/', $config->attachmentStoragePath));
        self::assertTrue($config->directMessageInspectionEnabled);
        self::assertSame('super_admin', $config->directMessageInspectionRole);
        self::assertFalse($config->messageRevisionReviewEnabled);
        self::assertSame('super_admin', $config->messageRevisionReviewRole);
        self::assertSame(10, $config->rateLimitPolicy('login')->maximumAttempts);
        self::assertSame(900, $config->rateLimitPolicy('login')->windowSeconds);
        self::assertSame(30, $config->rateLimitPolicy('room_send')->maximumAttempts);
        self::assertSame(60, $config->rateLimitPolicy('room_send')->windowSeconds);
        self::assertSame(60, $config->rateLimitPolicy('room_invite')->maximumAttempts);
        self::assertSame(3_600, $config->rateLimitPolicy('room_invite')->windowSeconds);
    }

    public function testBooleanEnvironmentValuesAreParsed(): void
    {
        putenv('APP_DEBUG=yes');
        putenv('DM_ADMIN_INSPECTION_ENABLED=no');
        putenv('MESSAGE_REVISION_REVIEW_ENABLED=yes');

        $config = Config::fromEnvironment();
        self::assertTrue($config->debug);
        self::assertFalse($config->directMessageInspectionEnabled);
        self::assertTrue($config->messageRevisionReviewEnabled);
    }

    public function testNamedRateLimitEnvironmentValuesOverrideDefaults(): void
    {
        putenv('RATE_LIMIT_ROOM_SEND_MAX_ATTEMPTS=75');
        putenv('RATE_LIMIT_ROOM_SEND_WINDOW_SECONDS=120');

        $policy = Config::fromEnvironment()->rateLimitPolicy('room_send');
        self::assertSame(75, $policy->maximumAttempts);
        self::assertSame(120, $policy->windowSeconds);
    }

    public function testLegacyLoginSettingsRemainTheNamedLoginDefaults(): void
    {
        putenv('LOGIN_MAX_ATTEMPTS=7');
        putenv('LOGIN_LOCK_MINUTES=3');

        $policy = Config::fromEnvironment()->rateLimitPolicy('login');
        self::assertSame(7, $policy->maximumAttempts);
        self::assertSame(180, $policy->windowSeconds);
    }

    public function testNamedLoginSettingsOverrideLegacyDefaults(): void
    {
        putenv('LOGIN_MAX_ATTEMPTS=7');
        putenv('LOGIN_LOCK_MINUTES=3');
        putenv('RATE_LIMIT_LOGIN_MAX_ATTEMPTS=9');
        putenv('RATE_LIMIT_LOGIN_WINDOW_SECONDS=240');

        $policy = Config::fromEnvironment()->rateLimitPolicy('login');
        self::assertSame(9, $policy->maximumAttempts);
        self::assertSame(240, $policy->windowSeconds);
    }

    public function testRateLimitBoundsAreValidated(): void
    {
        putenv('RATE_LIMIT_ROOM_SEND_MAX_ATTEMPTS=1001');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('RATE_LIMIT_ROOM_SEND_MAX_ATTEMPTS');
        Config::fromEnvironment();
    }

    public function testPresenceLeaseRangeIsValidated(): void
    {
        putenv('PRESENCE_LEASE_SECONDS=10');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PRESENCE_LEASE_SECONDS');
        Config::fromEnvironment();
    }

    public function testSseConnectionLeaseRangeIsValidated(): void
    {
        putenv('SSE_CONNECTION_LEASE_SECONDS=10');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SSE_CONNECTION_LEASE_SECONDS');
        Config::fromEnvironment();
    }

    public function testMetricsTokenMustBeLongWhenEnabled(): void
    {
        putenv('METRICS_BEARER_TOKEN=too-short');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('METRICS_BEARER_TOKEN');
        Config::fromEnvironment();
    }

    public function testMaintenanceMaximumAgeRangeIsValidated(): void
    {
        putenv('MAINTENANCE_MAX_AGE_HOURS=0');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MAINTENANCE_MAX_AGE_HOURS');
        Config::fromEnvironment();
    }

    public function testPrivilegedStepUpMaximumAgeRangeIsValidated(): void
    {
        putenv('PRIVILEGED_STEP_UP_MAX_AGE_SECONDS=30');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PRIVILEGED_STEP_UP_MAX_AGE_SECONDS');
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

    public function testMessageRevisionReviewRoleIsValidated(): void
    {
        putenv('MESSAGE_REVISION_REVIEW_ROLE=global_moderator');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MESSAGE_REVISION_REVIEW_ROLE');
        Config::fromEnvironment();
    }

    public function testWebPushIsDisabledByDefault(): void
    {
        $config = Config::fromEnvironment();
        self::assertFalse($config->webPushEnabled());
    }

    public function testWebPushVapidKeysMustBeConfiguredTogether(): void
    {
        putenv('WEB_PUSH_VAPID_PUBLIC_KEY=public-key-value');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WEB_PUSH_VAPID_PUBLIC_KEY and WEB_PUSH_VAPID_PRIVATE_KEY');
        Config::fromEnvironment();
    }

    public function testWebPushRequiresASubjectWhenEnabled(): void
    {
        putenv('WEB_PUSH_VAPID_PUBLIC_KEY=public-key-value');
        putenv('WEB_PUSH_VAPID_PRIVATE_KEY=private-key-value');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WEB_PUSH_VAPID_SUBJECT');
        Config::fromEnvironment();
    }

    public function testWebPushSubjectMustBeAMailtoAddressOrHttpsUrl(): void
    {
        putenv('WEB_PUSH_VAPID_PUBLIC_KEY=public-key-value');
        putenv('WEB_PUSH_VAPID_PRIVATE_KEY=private-key-value');
        putenv('WEB_PUSH_VAPID_SUBJECT=admin@example.org');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WEB_PUSH_VAPID_SUBJECT must be a mailto: address or an https: URL.');
        Config::fromEnvironment();
    }

    public function testWebPushIsEnabledWhenFullyConfigured(): void
    {
        putenv('WEB_PUSH_VAPID_PUBLIC_KEY=public-key-value');
        putenv('WEB_PUSH_VAPID_PRIVATE_KEY=private-key-value');
        putenv('WEB_PUSH_VAPID_SUBJECT=mailto:admin@example.org');

        $config = Config::fromEnvironment();
        self::assertTrue($config->webPushEnabled());
        self::assertSame('public-key-value', $config->webPushVapidPublicKey);
    }
}
