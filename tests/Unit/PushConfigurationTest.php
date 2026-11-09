<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Config;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\WebPush\PushAddressPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PushConfigurationTest extends TestCase
{
    public function testPrivateProviderConfigIsBoundedAndValidatedIndependentOfKeyEnablement(): void
    {
        self::assertSame([['origin' => 'https://push.example:8443', 'cidrs' => ['10.0.0.0/8']]],
            PushAddressPolicy::fromJson('[{"cidrs":["10.0.0.0/8"],"origin":"https://push.example:8443"}]'));
        foreach (['null', '{}', '[{}]', '[{"origin":"http://push.example","cidrs":["10.0.0.0/8"]}]',
            '[{"origin":"https://push.example/path","cidrs":["10.0.0.0/8"]}]',
            '[{"origin":"https://push.example","cidrs":["10.0.0.0/33"]}]',
            '[{"origin":"https://push.example","cidrs":[]}]'] as $json) {
            try {
                PushAddressPolicy::fromJson($json);
                self::fail('Invalid configuration was accepted.');
            } catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testExplicitResolverOverrideMustBeReadableWhileAnAbsentImplicitDefaultDoesNotDisableTheApp(): void
    {
        $previous = getenv('WEB_PUSH_DNS_RESOLV_CONF');
        $path = TestScratch::file('push-resolv-conf');
        try {
            putenv('WEB_PUSH_DNS_RESOLV_CONF');
            self::assertSame('/etc/resolv.conf', Config::fromEnvironment()->webPushDnsResolvConf);
            file_put_contents($path, "nameserver 8.8.8.8\n");
            putenv('WEB_PUSH_DNS_RESOLV_CONF=' . $path);
            self::assertSame($path, Config::fromEnvironment()->webPushDnsResolvConf);
            TestScratch::remove($path);
            try {
                Config::fromEnvironment();
                self::fail('Missing explicit resolver file was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('WEB_PUSH_DNS_RESOLV_CONF must be a readable file.', $exception->getMessage());
                self::assertStringNotContainsString($path, $exception->getMessage());
            }
        } finally {
            putenv($previous === false ? 'WEB_PUSH_DNS_RESOLV_CONF' : 'WEB_PUSH_DNS_RESOLV_CONF=' . $previous);
            TestScratch::remove($path);
        }
    }
}