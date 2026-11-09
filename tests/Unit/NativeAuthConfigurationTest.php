<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class NativeAuthConfigurationTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): Config
    {
        $base = Config::fromEnvironment();
        $values = [];
        foreach ((new \ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $values[$name] = array_key_exists($name, $overrides) ? $overrides[$name] : $base->{$name};
        }
        return new Config(...$values);
    }

    public function testNativeHandoffRequiresExplicitHttpsOriginAndItsMatchingCallback(): void
    {
        $valid = ['environment' => 'test', 'applicationOrigin' => 'https://chat.test',
            'oidcRedirectOrigin' => 'https://chat.test',
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat',
                'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]],
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']]];
        self::assertTrue(NativeConfig::configured($this->config($valid)));
        foreach ([
            ['applicationOrigin' => ''],
            ['applicationOrigin' => 'http://127.0.0.1', 'oidcRedirectOrigin' => 'http://127.0.0.1'],
            ['oidcRedirectOrigin' => 'https://other.test'],
            ['nativeAndroidApps' => []], ['oidcProviders' => []],
        ] as $invalid) {
            self::assertFalse(NativeConfig::configured($this->config(array_replace($valid, $invalid))));
        }
        $config = $this->config($valid);
        self::assertSame('de.networkxxiii.chitchat:/native-auth/callback', NativeConfig::redirect($config, 'de.networkxxiii.chitchat'));
        self::assertNotSame(NativeConfig::fingerprint($config),
            NativeConfig::fingerprint($this->config(array_replace($valid, ['applicationOrigin' => 'https://other.test']))));
        $this->expectException(ApiException::class);
        NativeConfig::redirect($config, 'de.networkxxiii.chitchat.debug');
    }

    public function testPkceUsesS256CanonicalChallengesAndRfcVerifierBounds(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        self::assertTrue(NativeConfig::verifierMatches($verifier, $challenge));
        self::assertFalse(NativeConfig::verifierMatches(str_repeat('a', 42), $challenge));
        self::assertFalse(NativeConfig::verifierMatches($verifier . ' ', $challenge));
        self::assertTrue(NativeConfig::validToken(NativeConfig::token()));
        self::assertFalse(NativeConfig::validToken(str_repeat('B', 43)));
        NativeConfig::requireState(str_repeat('a', 32));
        NativeConfig::requireState(str_repeat('z', 128));
        $this->expectException(ApiException::class);
        NativeConfig::requireState(str_repeat('a', 129));
    }
    public function testTrustedPackagesMustAlsoFormAValidReturnSchemeWithoutChangingPasskeyTrust(): void
    {
        $app = ['package' => 'de.example.chat_debug', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))];
        $config = $this->config(['applicationOrigin' => 'https://chat.test', 'oidcRedirectOrigin' => 'https://chat.test',
            'nativeAndroidApps' => [$app], 'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']]]);
        self::assertSame([$app], $config->nativeAndroidApps);
        self::assertFalse(NativeConfig::configured($config));
        $this->expectException(ApiException::class);
        NativeConfig::redirect($config, $app['package']);
    }
}
