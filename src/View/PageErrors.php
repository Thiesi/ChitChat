<?php

declare(strict_types=1);

namespace ChitChat\View;

use ChitChat\Config;
use ChitChat\Http\SecurityHeaders;
use Throwable;

/**
 * An uncaught failure while rendering a page (S7, #173) shows the 500 status
 * page instead of a blank one: an uncaught Throwable, and since #345 a true
 * fatal too (memory, PHP's own time limit, a fatal error in an include). A worker
 * the process manager kills (PHP-FPM's request_terminate_timeout) runs no PHP at
 * all, so that one still ends without a page. Only for a
 * browser asking for a page: API requests answer in JSON through
 * Endpoint::run, the command line keeps PHP's own output, APP_DEBUG keeps
 * PHP's own trace on screen, and once a page has started sending, nothing is
 * added to it. The failure is logged in full, as PHP would have.
 */
final class PageErrors
{
    /** Fatal errors PHP ends the request on, and that no exception handler sees. */
    private const FATAL = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR];

    /**
     * Headers a page may have queued before it failed, which must not travel with the 500 page.
     * Set-Cookie stays: dropping a rotated session cookie would sign the person out.
     */
    private const STALE_HEADERS = ['Location', 'Refresh', 'Content-Length', 'Content-Disposition', 'Content-Type', 'ETag', 'Last-Modified', 'Content-Security-Policy'];

    /** How much more memory the status page may use after a fatal, on top of what's in use. */
    private const RENDER_MEMORY_BYTES = 8 * 1024 * 1024;

    private static bool $handled = false;

    public static function install(Config $config): void
    {
        if ($config->debug || PHP_SAPI === 'cli' || !self::wantsPage($_SERVER)) {
            return;
        }
        set_exception_handler(static function (Throwable $error) use ($config): void {
            // The same line PHP writes, with the trace and any chained exceptions, so existing
            // log searches keep matching and the call path isn't lost.
            error_log('PHP Fatal error:  Uncaught ' . $error);
            self::showFailure($config);
        });
        register_shutdown_function(static function () use ($config): void {
            $error = error_get_last();
            // PHP has already logged a fatal error itself.
            if (is_array($error) && in_array($error['type'], self::FATAL, true)) {
                self::allowMemoryForThePage();
                self::showFailure($config);
            }
        });
    }

    /**
     * A page that grew right up to memory_limit still holds that memory while shutdown runs,
     * so rendering the status page would fail again: give it a few megabytes more.
     */
    private static function allowMemoryForThePage(): void
    {
        // Unlimited stays unlimited; otherwise a fixed 8 MiB above what's in use, never -1.
        if (ini_get('memory_limit') === '-1') {
            return;
        }
        ini_set('memory_limit', (string) (memory_get_usage(true) + self::RENDER_MEMORY_BYTES));
    }

    private static function showFailure(Config $config): void
    {
        if (self::$handled || headers_sent()) {
            return;
        }
        self::$handled = true;
        self::discardBufferedOutput();
        foreach (self::STALE_HEADERS as $name) {
            header_remove($name);
        }
        SecurityHeaders::send($config);
        // The configured name, not the database's: the database may be why the page failed.
        StatusPage::respond(500, $config->applicationName);
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
        $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        // The script that runs decides (#345): //api/… confuses the raw address, not SCRIPT_NAME.
        $script = is_string($server['SCRIPT_NAME'] ?? null) ? $server['SCRIPT_NAME'] : '';
        $collapsed = (string) preg_replace('#/{2,}#', '/', $path);
        if (str_starts_with($script, '/api/') || str_starts_with($collapsed, '/api/')) {
            return false;
        }
        $accept = is_string($server['HTTP_ACCEPT'] ?? null) ? $server['HTTP_ACCEPT'] : '';

        return str_contains($accept, 'text/html');
    }
}
