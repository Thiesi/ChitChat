<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\Base64Url;
use ChitChat\Auth\Oidc\OidcProvider;
use ChitChat\Config;
use ChitChat\Http\ApiException;

final class NativeAuthConfiguration
{
    public const TRANSACTION_TTL = 600;
    public const CODE_TTL = 90;
    public const TOMBSTONE_TTL = 3600;

    public static function configured(Config $config): bool
    {
        return $config->nativeAndroidApps !== []
            && str_starts_with($config->applicationOrigin, 'https://')
            && $config->oidcRedirectOrigin === $config->applicationOrigin
            && OidcProvider::configured($config) !== [];
    }

    public static function available(Config $config): bool
    {
        return self::configured($config) && \ChitChat\View\NativeAuthPage::integrated();
    }

    public static function requireConfigured(Config $config): void
    {
        if (!self::configured($config)) {
            throw new ApiException(503, 'native_auth_unavailable',
                'Native sign-in needs trusted Android apps, a provider and an explicit matching HTTPS APP_ORIGIN.');
        }
    }

    public static function redirect(Config $config, string $clientId): string
    {
        foreach ($config->nativeAndroidApps as $app) {
            if ($app['package'] === $clientId) {
                return $clientId . ':/native-auth/callback';
            }
        }
        throw new ApiException(403, 'native_client_unavailable', 'This app is not approved for native sign-in here.');
    }

    public static function fingerprint(Config $config): string
    {
        return hash('sha256', json_encode([
            $config->nativeAndroidApps, $config->applicationOrigin, $config->oidcRedirectOrigin,
            $config->oidcProviders, $config->webauthnRpId, $config->webauthnOrigin,
        ], JSON_THROW_ON_ERROR));
    }

    public static function token(): string
    {
        return Base64Url::encode(random_bytes(32));
    }

    public static function validToken(string $value): bool
    {
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $value) !== 1) return false;
        $bytes = base64_decode(strtr($value, '-_', '+/') . '=', true);
        return is_string($bytes) && strlen($bytes) === 32 && Base64Url::encode($bytes) === $value;
    }

    public static function requireState(string $state): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $state) !== 1) {
            throw new ApiException(400, 'validation_error', 'state must contain 32–128 base64url characters.');
        }
    }

    public static function verifierMatches(string $verifier, string $challenge): bool
    {
        return preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D', $verifier) === 1
            && hash_equals($challenge, Base64Url::encode(hash('sha256', $verifier, true)));
    }
}
