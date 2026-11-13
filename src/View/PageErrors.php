<?php

declare(strict_types=1);

namespace ChitChat\View;

use Throwable;

/**
 * An uncaught failure while rendering a page (S7, #173) shows the 500 status
 * page instead of a blank one. Only for a browser asking for a page: API
 * requests answer in JSON through Endpoint::run, the command line keeps PHP's
 * own output, and once a page has started sending, nothing is added to it.
 * The failure is still logged.
 */
final class PageErrors
{
    public static function install(): void
    {
        if (PHP_SAPI === 'cli' || !self::wantsPage($_SERVER)) {
            return;
        }
        set_exception_handler(static function (Throwable $error): void {
            error_log('Uncaught ' . $error::class . ': ' . $error->getMessage() . ' in ' . $error->getFile() . ':' . $error->getLine());
            if (headers_sent()) {
                return;
            }
            StatusPage::respond(500);
        });
    }

    /** @param array<string, mixed> $server */
    public static function wantsPage(array $server): bool
    {
        $path = parse_url(is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/', PHP_URL_PATH);
        if (!is_string($path) || str_starts_with($path, '/api/')) {
            return false;
        }
        $accept = is_string($server['HTTP_ACCEPT'] ?? null) ? $server['HTTP_ACCEPT'] : '';

        return str_contains($accept, 'text/html');
    }
}
