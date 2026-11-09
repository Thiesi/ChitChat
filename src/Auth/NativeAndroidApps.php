<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use InvalidArgumentException;
use JsonException;

/** @phpstan-type AndroidApp array{package:string,sha256_cert_fingerprint:string} */
final class NativeAndroidApps
{
    public const MAX_ENTRIES = 32;
    public const MAX_JSON_BYTES = 16_384;

    /** @return list<AndroidApp> */
    public static function fromJson(string $json): array
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException('NATIVE_ANDROID_APPS exceeds the 16384-byte configuration limit.');
        }
        try {
            $entries = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('NATIVE_ANDROID_APPS must be a JSON list of package/certificate pairs.');
        }
        if (!is_array($entries) || !str_starts_with(ltrim($json), '[')) {
            throw new InvalidArgumentException('NATIVE_ANDROID_APPS must be a JSON list.');
        }
        return self::normalize($entries);
    }

    /**
     * @param array<mixed> $entries
     * @return list<AndroidApp>
     */
    public static function normalize(array $entries): array
    {
        if (!array_is_list($entries) || count($entries) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException('NATIVE_ANDROID_APPS must contain at most 32 package/certificate pairs.');
        }
        $result = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 2
                || !is_string($entry['package'] ?? null) || !is_string($entry['sha256_cert_fingerprint'] ?? null)
                || array_diff(array_keys($entry), ['package', 'sha256_cert_fingerprint']) !== []) {
                throw new InvalidArgumentException('Each NATIVE_ANDROID_APPS entry must contain only package and sha256_cert_fingerprint strings.');
            }
            $package = $entry['package'];
            $fingerprint = $entry['sha256_cert_fingerprint'];
            if (strlen($package) > 255
                || preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/D', $package) !== 1) {
                throw new InvalidArgumentException('NATIVE_ANDROID_APPS contains an invalid Android package name.');
            }
            if (preg_match('/\A(?:[0-9a-fA-F]{2}:){31}[0-9a-fA-F]{2}\z/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('NATIVE_ANDROID_APPS fingerprints must contain exactly 32 colon-separated SHA-256 bytes.');
            }
            $fingerprint = strtoupper($fingerprint);
            $result[$package . ':' . $fingerprint] = ['package' => $package, 'sha256_cert_fingerprint' => $fingerprint];
        }
        ksort($result, SORT_STRING);
        return array_values($result);
    }

    /**
     * @param list<AndroidApp> $apps
     * @return list<string>
     */
    public static function origins(array $apps): array
    {
        $origins = [];
        foreach ($apps as $app) {
            $digest = hex2bin(str_replace(':', '', $app['sha256_cert_fingerprint']));
            if ($digest === false || strlen($digest) !== 32) {
                throw new InvalidArgumentException('Native Android certificate digests must contain 32 bytes.');
            }
            $origin = 'android:apk-key-hash:' . Base64Url::encode($digest);
            $origins[$origin] = $origin;
        }
        sort($origins, SORT_STRING);
        return $origins;
    }

    /**
     * @param list<AndroidApp> $apps
     * @return list<array{relation:list<string>,target:array{namespace:string,package_name:string,sha256_cert_fingerprints:list<string>}}>
     */
    public static function assetLinks(array $apps): array
    {
        $packages = [];
        foreach ($apps as $app) {
            $packages[$app['package']][$app['sha256_cert_fingerprint']] = $app['sha256_cert_fingerprint'];
        }
        ksort($packages, SORT_STRING);
        $statements = [];
        foreach ($packages as $package => $fingerprints) {
            sort($fingerprints, SORT_STRING);
            $statements[] = [
                'relation' => ['delegate_permission/common.get_login_creds'],
                'target' => ['namespace' => 'android_app', 'package_name' => $package,
                    'sha256_cert_fingerprints' => $fingerprints],
            ];
        }
        return $statements;
    }
}
