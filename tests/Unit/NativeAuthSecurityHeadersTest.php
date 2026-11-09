<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Config;
use ChitChat\Http\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class NativeAuthSecurityHeadersTest extends TestCase
{
    /** @param array<string,mixed> $changes */
    private function config(array $changes = []): Config
    {
        $base = Config::fromEnvironment();
        $args = [];
        foreach ((new \ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $args[$name] = $changes[$name] ?? $base->{$name};
        }
        return new Config(...$args);
    }

    public function testOnlyNativeConfirmationAllowsEnabledAuthorizationOrigins(): void
    {
        $settings = ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => ''];
        $config = $this->config(['oidcRedirectOrigin' => 'https://chat.test', 'oidcProviders' => ['google' => $settings, 'twitch' => $settings]]);
        $global = SecurityHeaders::policy($config);
        self::assertStringEndsWith("form-action 'self'", $global);
        self::assertStringNotContainsString('google.com', $global);
        $native = SecurityHeaders::policy($config, true);
        self::assertSame(str_replace("form-action 'self'", "form-action 'self' https://accounts.google.com https://id.twitch.tv", $global), $native);
        self::assertStringNotContainsString('*', $native);
        self::assertSame($global, SecurityHeaders::policy($this->config(['oidcProviders' => []]), true));
    }

    public function testOverridesUseOnlyTheExistingNonProductionProviderConfigurationAndAnExactOrigin(): void
    {
        $providers = ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => 'http://127.0.0.1:9123/provider']];
        $config = $this->config(['environment' => 'test', 'oidcRedirectOrigin' => 'https://chat.test', 'oidcProviders' => $providers]);
        self::assertStringEndsWith("form-action 'self' http://127.0.0.1:9123", SecurityHeaders::policy($config, true));
        self::assertStringEndsWith("form-action 'self'", SecurityHeaders::policy($config));
        foreach (["https://idp.test;form-action *", "https://user@idp.test", "javascript:alert(1)"] as $bad) {
            $providers['google']['endpoint_override'] = $bad;
            try {
                SecurityHeaders::policy($this->config(['environment' => 'test', 'oidcRedirectOrigin' => 'https://chat.test', 'oidcProviders' => $providers]), true);
                self::fail('Malformed CSP source was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $providers['google']['endpoint_override'] = 'http://127.0.0.1:9123';
        $this->expectException(\InvalidArgumentException::class);
        SecurityHeaders::policy($this->config(['environment' => 'production', 'oidcRedirectOrigin' => 'https://chat.test', 'oidcProviders' => $providers]), true);
    }
}