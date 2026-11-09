<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\Base64Url;
use ChitChat\Http\ApiException;

/** Double-submit bootstrap CSRF before PHP may recover its temporarily withheld Strict session cookie. */
final class NativeAuthBootstrap
{
    public const COOKIE = '__Host-chitchat-native';

    /** @return array{cookie:string,hash:string,csrf_token:string} */
    public static function create(string $transactionId, string $kind): array
    {
        if (!NativeAuthConfiguration::validToken($transactionId) || !in_array($kind, ['entry', 'callback'], true)) {
            throw new \InvalidArgumentException('Invalid native bootstrap context.');
        }
        $nonce = NativeAuthConfiguration::token();
        $cookie = $transactionId . '.' . $kind . '.' . $nonce;
        return ['cookie' => $cookie, 'hash' => hash('sha256', $nonce),
            'csrf_token' => self::csrf($transactionId, $kind, $nonce)];
    }

    /** @return array{id:string,kind:string,hash:string,csrf_token:string}|null */
    public static function parse(string $cookie): ?array
    {
        if (strlen($cookie) > 100) return null;
        $parts = explode('.', $cookie);
        if (count($parts) !== 3 || !NativeAuthConfiguration::validToken($parts[0])
            || !in_array($parts[1], ['entry', 'callback'], true)
            || !NativeAuthConfiguration::validToken($parts[2])) return null;
        return ['id' => $parts[0], 'kind' => $parts[1], 'hash' => hash('sha256', $parts[2]),
            'csrf_token' => self::csrf($parts[0], $parts[1], $parts[2])];
    }

    /** @return array{id:string,kind:string,hash:string,csrf_token:string} */
    public static function verify(string $cookie, string $csrfToken, string $expectedKind): array
    {
        $parsed = self::parse($cookie);
        if ($parsed === null) throw NativeAuthGuard::failure('expired');
        if ($parsed['kind'] !== $expectedKind) {
            throw new ApiException(403, 'bootstrap_kind_mismatch', 'This bootstrap belongs to another operation.');
        }
        if ($csrfToken === '' || !hash_equals($parsed['csrf_token'], $csrfToken)) {
            throw new ApiException(403, 'csrf_failed', 'The CSRF token is missing or invalid.');
        }
        return $parsed;
    }

    public static function send(string $cookie, int $remainingSeconds): void
    {
        if ($remainingSeconds < 1 || $remainingSeconds > NativeAuthConfiguration::TRANSACTION_TTL
            || self::parse($cookie) === null) throw new \InvalidArgumentException('Invalid bootstrap lifetime.');
        if (!setcookie(self::COOKIE, $cookie, ['expires' => time() + $remainingSeconds, 'path' => '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Strict'])) {
            throw new ApiException(500, 'native_bootstrap_unavailable', 'Unable to prepare this browser request.');
        }
    }

    public static function clear(): void
    {
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    }

    private static function csrf(string $id, string $kind, string $nonce): string
    {
        return Base64Url::encode(hash_hmac('sha256', 'native-bootstrap:' . $id . ':' . $kind, $nonce, true));
    }
}
