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

    /** @return array{store_url:string, open_url:?string, server_address:?string}|null */
    private function links(string $origin): ?array
    {
        return AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => $origin]), self::STORE);
    }

    public function testShipsDarkUntilAReleaseSetsTheStoreUrl(): void
    {
        self::assertNull((new \ReflectionClassConstant(AndroidAppPromotion::class, 'STORE_URL'))->getValue());
        self::assertFalse(AndroidAppPromotion::available());
        // The column alone is never enough: nothing is exposed while the store URL is unknown.
        self::assertNull(AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => 'https://chat.example.org'])));
        self::assertNull(AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => 'https://chat.example.org']), null));
    }

    public function testOnceSetTheStoreUrlIsTheDocumentedPlayListing(): void
    {
        $storeUrl = (new \ReflectionClassConstant(AndroidAppPromotion::class, 'STORE_URL'))->getValue();
        if ($storeUrl === null) {
            self::markTestSkipped('STORE_URL is null until the Play listing is public.');
        }
        // The release that sets it: the plain listing, no tracking or personal parameters.
        self::assertSame(self::STORE, $storeUrl);
    }

    public function testAnEmptyStoreUrlCountsAsUnknown(): void
    {
        self::assertFalse(AndroidAppPromotion::available(''));
        self::assertNull(AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => 'https://chat.example.org']), ''));
    }

    public function testTheReferrerStartsTheQueryWhenTheListingHasNone(): void
    {
        $links = AndroidAppPromotion::toPublic(true, $this->config(['applicationOrigin' => 'https://chat.example.org']), 'https://play.example/app');
        self::assertIsArray($links);
        self::assertSame('https://play.example/app?referrer=chitchat_server%3Dchat.example.org', $links['store_url']);
        self::assertStringEndsWith(';S.browser_fallback_url=' . rawurlencode($links['store_url']) . ';end', (string) $links['open_url']);
    }

    public function testNothingWhenSwitchedOff(): void
    {
        self::assertTrue(AndroidAppPromotion::available(self::STORE));
        self::assertNull(AndroidAppPromotion::toPublic(false, $this->config(['applicationOrigin' => 'https://chat.example.org']), self::STORE));
    }

    public function testExactLinksForAHost(): void
    {
        self::assertSame([
            'store_url' => 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat&referrer=chitchat_server%3Dchat.example.org',
            'open_url' => 'intent://connect?server=chat.example.org#Intent;scheme=chitchat;package=de.networkxxiii.chitchat;'
                . 'S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dde.networkxxiii.chitchat'
                . '%26referrer%3Dchitchat_server%253Dchat.example.org;end',
            'server_address' => 'chat.example.org',
        ], $this->links('https://chat.example.org'));
    }

    public function testExactLinksForAHostWithAPort(): void
    {
        self::assertSame([
            'store_url' => 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat&referrer=chitchat_server%3Dchat.example.org%3A8443',
            'open_url' => 'intent://connect?server=chat.example.org%3A8443#Intent;scheme=chitchat;package=de.networkxxiii.chitchat;'
                . 'S.browser_fallback_url=https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dde.networkxxiii.chitchat'
                . '%26referrer%3Dchitchat_server%253Dchat.example.org%253A8443;end',
            'server_address' => 'chat.example.org:8443',
        ], $this->links('https://chat.example.org:8443'));
    }

    public function testPort443IsOmittedAndTheHostIsLowercaseWithoutAPath(): void
    {
        $expected = $this->links('https://chat.example.org');
        self::assertSame($expected, $this->links('https://chat.example.org:443'));
        self::assertSame($expected, $this->links('https://Chat.Example.ORG/'));
    }

    public function testIpv6KeepsItsBrackets(): void
    {
        self::assertSame('[2001:db8::1]:8443', AndroidAppPromotion::serverAddress($this->config(['applicationOrigin' => 'https://[2001:db8::1]:8443'])));
        self::assertStringStartsWith('intent://connect?server=%5B2001%3Adb8%3A%3A1%5D%3A8443#Intent;', (string) $this->links('https://[2001:db8::1]:8443')['open_url']);
    }

    public function testWithoutAnHttpsOriginOnlyThePlainListing(): void
    {
        $plain = ['store_url' => self::STORE, 'open_url' => null, 'server_address' => null];
        // Unset, or http (only allowed for local development).
        foreach (['', 'http://localhost:8080', 'http://localhost'] as $origin) {
            self::assertNull(AndroidAppPromotion::serverAddress($this->config(['applicationOrigin' => $origin])), $origin);
            self::assertSame($plain, $this->links($origin), $origin);
        }
    }

    public function testTheRequestHostNeverChangesTheLinks(): void
    {
        $expected = $this->links('https://chat.example.org');
        $saved = $_SERVER;
        try {
            $_SERVER['HTTP_HOST'] = 'evil.example';
            $_SERVER['SERVER_NAME'] = 'evil.example';
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'evil.example';
            self::assertSame($expected, $this->links('https://chat.example.org'));
            // Without APP_ORIGIN there's no address at all, rather than one from the request.
            self::assertSame(['store_url' => self::STORE, 'open_url' => null, 'server_address' => null], $this->links(''));
        } finally {
            $_SERVER = $saved;
        }
    }
}
