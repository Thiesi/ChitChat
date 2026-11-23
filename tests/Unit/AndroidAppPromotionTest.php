<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Admin\AndroidAppPromotion;
use ChitChat\Config;
use PHPUnit\Framework\TestCase;

final class AndroidAppPromotionTest extends TestCase
{
    private const STORE = 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat';

    /** @param array<string,mixed> $changes */
    private function config(array $changes = []): Config
    {
        $base = Config::fromEnvironment();
        $args = [];
        foreach ((new \ReflectionClass(Config::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $args[$name] = array_key_exists($name, $changes) ? $changes[$name] : $base->{$name};
        }
        return new Config(...$args);
    }

    public function testShipsDarkUntilAReleaseSetsTheStoreUrl(): void
    {
        self::assertNull((new \ReflectionClassConstant(AndroidAppPromotion::class, 'STORE_URL'))->getValue());
        self::assertFalse(AndroidAppPromotion::available());
        // The column alone is never enough: nothing is exposed while the store URL is unknown.
        self::assertNull(AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => 'https://chat.example.org'])));
    }

    public function testExposedOnlyWhenSwitchedOnAndTheStoreUrlIsKnown(): void
    {
        $config = $this->config(['applicationOrigin' => 'https://chat.example.org']);
        self::assertTrue(AndroidAppPromotion::available(self::STORE));
        self::assertNull(AndroidAppPromotion::toPublic(false, $config, self::STORE));
        self::assertNull(AndroidAppPromotion::toPublic(true, $config, null));
        self::assertSame(
            ['store_url' => self::STORE, 'server_address' => 'chat.example.org'],
            AndroidAppPromotion::toPublic(true, $config, self::STORE),
        );
    }

    public function testServerAddressIsTheBareHostFromAppOrigin(): void
    {
        foreach ([
            'https://chat.example.org' => 'chat.example.org',
            'https://Chat.Example.ORG/' => 'chat.example.org',
            'https://chat.example.org:443' => 'chat.example.org',
            'https://chat.example.org:8443' => 'chat.example.org:8443',
            'http://localhost:8080' => 'localhost:8080',
            'http://localhost:80' => 'localhost',
            'https://[2001:db8::1]:8443' => '[2001:db8::1]:8443',
        ] as $origin => $expected) {
            self::assertSame($expected, AndroidAppPromotion::serverAddress($this->config(['applicationOrigin' => $origin])), $origin);
        }
    }

    public function testNoAddressWithoutAppOriginOnlyTheBadge(): void
    {
        $config = $this->config(['applicationOrigin' => '']);
        self::assertNull(AndroidAppPromotion::serverAddress($config));
        self::assertSame(['store_url' => self::STORE, 'server_address' => null], AndroidAppPromotion::toPublic(true, $config, self::STORE));
    }

    public function testTheRequestHostNeverChangesTheAddress(): void
    {
        $saved = $_SERVER;
        try {
            $_SERVER['HTTP_HOST'] = 'evil.example';
            $_SERVER['SERVER_NAME'] = 'evil.example';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
            self::assertSame(
                ['store_url' => self::STORE, 'server_address' => 'chat.example.org'],
                AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => 'https://chat.example.org']), self::STORE),
            );
            // Without APP_ORIGIN there's no address at all, rather than one from the request.
            self::assertNull(AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => '']), self::STORE)['server_address'] ?? null);
        } finally {
            $_SERVER = $saved;
        }
    }
}
