<?php

declare(strict_types=1);

namespace ChitChat\View;

use Throwable;

/**
 * An uncaught failure while rendering a page (S7, #173) shows the 500 status
 * page instead of a blank one. Only for a browser asking for a page: API
 * requests answer in JSON through Endpoint::run, the command line keeps PHP's
 * own output, APP_DEBUG keeps PHP's own trace on screen, and once a page has
 * started sending, nothing is added to it. The failure is logged in full, as
 * PHP would have.
 */
final class PageErrors
{
    public static function install(bool $debug = false): void
    {
        if ($debug || PHP_SAPI === 'cli' || !self::wantsPage($_SERVER)) {
            return;
        }
        set_exception_handler(static function (Throwable $error): void {
            // The same line PHP writes, with the trace and any chained exceptions, so existing
            // log searches keep matching and the call path isn't lost.
            error_log('PHP Fatal error:  Uncaught ' . $error);
            if (headers_sent()) {
                return;
            }
            self::discardBufferedOutput();
            StatusPage::respond(500);
        });
    }

    /**
     * A half-rendered page can still sit in PHP's output buffer (output_buffering=4096 in
     * production) while headers_sent() is false: drop it, or the status page would follow it.
     */
    public static function discardBufferedOutput(int $downTo = 0): void
    {
        while (ob_get_level() > $downTo) {
            // A buffer started without PHP_OUTPUT_HANDLER_REMOVABLE can't go; stop rather than spin.
            if (!@ob_end_clean()) {
                break;
            }
        }
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
