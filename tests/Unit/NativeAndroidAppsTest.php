<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\NativeAndroidApps;
use ChitChat\Config;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeAndroidAppsTest extends TestCase
{
    private static function fingerprint(string $byte): string
    {
        return implode(':', array_fill(0, 32, $byte));
    }

    public function testTrustIsCanonicalBoundedDeduplicatedAndSharesOnlyLoginCredentials(): void
    {
        $a = ['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => self::fingerprint('ab')];
        $b = ['package' => $a['package'], 'sha256_cert_fingerprint' => self::fingerprint('CD')];
        $debug = ['package' => $a['package'] . '.debug', 'sha256_cert_fingerprint' => self::fingerprint('AB')];
        $apps = NativeAndroidApps::fromJson(json_encode([$b, $a, $a, $debug], JSON_THROW_ON_ERROR));
        self::assertCount(3, $apps);
        self::assertSame(self::fingerprint('AB'), $apps[0]['sha256_cert_fingerprint']);
        self::assertSame([
            'android:apk-key-hash:' . rtrim(strtr(base64_encode(str_repeat("\xab", 32)), '+/', '-_'), '='),
            'android:apk-key-hash:' . rtrim(strtr(base64_encode(str_repeat("\xcd", 32)), '+/', '-_'), '='),
        ], NativeAndroidApps::origins($apps), 'Use raw digest bytes, not the hex string or another hash.');
        $dal = NativeAndroidApps::assetLinks($apps);
        self::assertCount(2, $dal);
        self::assertSame(['delegate_permission/common.get_login_creds'], $dal[0]['relation']);
        self::assertSame('android_app', $dal[0]['target']['namespace']);
        self::assertSame($a['package'], $dal[0]['target']['package_name']);
        self::assertSame([self::fingerprint('AB'), self::fingerprint('CD')], $dal[0]['target']['sha256_cert_fingerprints']);
        self::assertSame([self::fingerprint('AB')], $dal[1]['target']['sha256_cert_fingerprints']);
        self::assertSame([], NativeAndroidApps::fromJson('[]'));
        self::assertSame([], NativeAndroidApps::origins([]));
        self::assertSame([], NativeAndroidApps::assetLinks([]));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidLists(): iterable
    {
        foreach (['', 'null', '{}', '"app"', '{', '[[]]', '["app"]'] as $index => $json) {
            yield 'non-list-or-entry-' . $index => [$json];
        }
        $valid = ['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => self::fingerprint('AB')];
        foreach (['app', '*.chitchat', 'de..app', '.de.app', 'de.2app', "de.app\n", str_repeat('a', 256) . '.app'] as $package) {
            yield 'package-' . bin2hex(substr($package, 0, 20)) => [json_encode([array_replace($valid, ['package' => $package])], JSON_THROW_ON_ERROR)];
        }
        foreach (['', self::fingerprint('GG'), implode(':', array_fill(0, 31, 'AB')),
            implode(':', array_fill(0, 33, 'AB')), str_repeat('AB', 32), self::fingerprint('AB') . ' ', false] as $index => $fingerprint) {
            yield 'fingerprint-' . $index => [json_encode([array_replace($valid, ['sha256_cert_fingerprint' => $fingerprint])], JSON_THROW_ON_ERROR)];
        }
        yield 'unknown-field' => [json_encode([$valid + ['origin' => 'https://evil.test']], JSON_THROW_ON_ERROR)];
        yield 'missing-field' => [json_encode([['package' => 'de.example.app']], JSON_THROW_ON_ERROR)];
        yield 'too-many-raw-pairs' => [json_encode(array_fill(0, 33, $valid), JSON_THROW_ON_ERROR)];
        yield 'oversized-json' => ['[' . str_repeat(' ', NativeAndroidApps::MAX_JSON_BYTES) . ']'];
    }

    #[DataProvider('invalidLists')]
    public function testInvalidTrustFailsClosedEvenWithPasskeysDisabled(string $json): void
    {
        $before = getenv('NATIVE_ANDROID_APPS');
        putenv('NATIVE_ANDROID_APPS=' . $json);
        try {
            $this->expectException(InvalidArgumentException::class);
            // Empty environment strings use the documented empty default.
            if ($json === '') {
                NativeAndroidApps::fromJson($json);
            } else {
                Config::fromEnvironment();
            }
        } finally {
            $before === false ? putenv('NATIVE_ANDROID_APPS') : putenv('NATIVE_ANDROID_APPS=' . $before);
        }
    }
}
