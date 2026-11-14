<?php

declare(strict_types=1);

namespace ChitChat\View;

/**
 * The error and not-found pages (S7, #173), in Lantern: a plain sentence, what
 * still works, and a way back to Rooms. It never needs the database: the
 * caller passes the installation's name when it could read it, and a page
 * that failed because the database is down still renders.
 */
final class StatusPage
{
    /** @var array<int, array{title: string, heading: string, text: string, still: string, retry: bool}> */
    private const PAGES = [
        404 => [
            'title' => 'Page not found',
            'heading' => 'There’s nothing here',
            'text' => 'This address doesn’t lead to a page. It may have been typed wrong, or the link is old.',
            'still' => 'Your rooms and messages are where you left them.',
            'retry' => false,
        ],
        403 => [
            'title' => 'Not available to you',
            'heading' => 'You can’t open this page',
            'text' => 'Your account doesn’t have access to it. If you think it should, ask an administrator.',
            'still' => 'Everything else you can use is still there.',
            'retry' => false,
        ],
        500 => [
            'title' => 'Something went wrong',
            'heading' => 'Something went wrong',
            'text' => 'The server couldn’t show this page just now. Try again in a moment.',
            'still' => 'Nothing you sent is lost by this.',
            'retry' => true,
        ],
    ];

    /** Sends the page with its status code; safe to call after other headers were sent. */
    public static function respond(int $status, ?string $appName = null): void
    {
        $status = isset(self::PAGES[$status]) ? $status : 500;
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // "Try again" reloads the same address; only a local path, never another site.
        $again = self::localAddress($uri);
        echo self::render($status, $appName, $again);
    }

    public static function render(int $status, ?string $appName = null, string $again = '/'): string
    {
        $page = self::PAGES[$status] ?? self::PAGES[500];
        $name = self::escape($appName !== null && trim($appName) !== '' ? $appName : 'ChitChat');
        $title = self::escape($page['title']);
        $heading = self::escape($page['heading']);
        $text = self::escape($page['text']);
        $still = self::escape($page['still']);
        // A plain link (the CSP allows no inline script): the same address again.
        $retry = $page['retry']
            ? '<a class="secondary-button" href="' . self::escape($again) . '">Try again</a>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex">
  <script src="/assets/js/theme.js"></script>
  <title>{$title} · {$name}</title>
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/status-page.css">
</head>
<body class="status-page-body">
  <main class="status-page" data-status="{$status}">
    <p class="status-page-eyebrow">{$name}</p>
    <h1>{$heading}</h1>
    <p class="status-page-text">{$text}</p>
    <p class="status-page-still">{$still}</p>
    <p class="status-page-actions"><a class="primary-button" href="/">Go to Rooms</a>{$retry}</p>
  </main>
</body>
</html>
HTML;
    }

    /** A path on this site, or "/": never //host or /\host, which browsers read as another site (#345). */
    public static function localAddress(mixed $uri): string
    {
        // Browsers drop tabs and line breaks from a URL before reading it ("/<tab>/host" is
        // "//host"), so any control character or space makes it "/" as well.
        if (!is_string($uri) || !str_starts_with($uri, '/') || str_starts_with($uri, '//') || str_starts_with($uri, '/\\')
            || preg_match('/[\x00-\x20\x7f]/', $uri) === 1) {
            return '/';
        }

        return $uri;
    }

    /** The installation's name, or null when it can't be read (the database is down, say). */
    public static function applicationName(\ChitChat\Config $config): ?string
    {
        try {
            return \ChitChat\Admin\ApplicationNameService::resolve($config);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
