<?php

declare(strict_types=1);

namespace ChitChat\Http;

use ChitChat\Config;
use ChitChat\Auth\Oidc\OidcProvider;

final class SecurityHeaders
{
    public static function send(Config $config, bool $nativeAuth = false): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('Cache-Control: no-store');
        header('Content-Security-Policy: ' . self::policy($config, $nativeAuth));
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');

        if ($config->sessionCookieSecure) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
    /** Native confirmation alone may redirect a form POST to an enabled provider. */
    public static function policy(Config $config, bool $nativeAuth = false): string
    {
        $origins = [];
        if ($nativeAuth) {
            foreach (OidcProvider::configured($config) as $provider) {
                if ($provider->endpointOverride !== null && !in_array($config->environment, ['development', 'test'], true)) {
                    throw new \InvalidArgumentException('Provider endpoint overrides are unavailable in production.');
                }
                $parts = parse_url($provider->authorizationEndpoint);
                $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
                $host = is_array($parts) ? ($parts['host'] ?? '') : '';
                if (!is_array($parts) || !in_array($scheme, ['https', 'http'], true)
                    || ($scheme !== 'https' && $provider->endpointOverride === null)
                    || isset($parts['user']) || isset($parts['pass'])
                    || preg_match('/\A(?:[A-Za-z0-9.-]+|\[[A-Fa-f0-9:]+\])\z/D', $host) !== 1) {
                    throw new \InvalidArgumentException('Invalid provider authorization origin.');
                }
                $origin = $scheme . '://' . strtolower($host) . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $origins[$origin] = true;
            }
        }
        return implode('; ', [
            "default-src 'self'", "script-src 'self'", "style-src 'self'", "img-src 'self' data:",
            "connect-src 'self'", "font-src 'self'", "object-src 'none'", "base-uri 'none'",
            "frame-ancestors 'none'", "form-action 'self'" . ($origins === [] ? '' : ' ' . implode(' ', array_keys($origins))),
        ]);
    }
}
