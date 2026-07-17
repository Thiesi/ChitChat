<?php

declare(strict_types=1);

namespace ChitChat\Http;

use ChitChat\Config;

final class SecurityHeaders
{
    public static function send(Config $config): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header('Cache-Control: no-store');
        header('Content-Security-Policy: ' . implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "font-src 'self'",
            "object-src 'none'",
            "base-uri 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
        ]));
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
}
