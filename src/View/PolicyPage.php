<?php

declare(strict_types=1);
namespace ChitChat\View;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Config;
use DateTimeImmutable;
use Throwable;

/**
 * The public page for one hosted document (/rules.php, /privacy.php): no
 * sign-in, phone-first, both themes, readable measure. External documents
 * redirect to the administrator's page; a missing document is a 404.
 */
final class PolicyPage
{
    private const TITLES = [
        PolicyLinks::RULES => 'Community rules',
        PolicyLinks::PRIVACY => 'Privacy policy',
    ];

    public static function respond(Config $config, string $kind): void
    {
        $document = PolicyLinks::document($config, $kind);
        if ($document === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo self::notFound($config, $kind);

            return;
        }
        if (($document['source'] ?? 'hosted') === 'external' && is_string($document['url'] ?? null)) {
            header('Location: ' . $document['url'], true, 302);

            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer');
        $embedded = ($_GET['embedded'] ?? '') === '1';
        echo self::render($config, $kind, $document, $embedded);
    }

    /** @param array<string, mixed> $document */
    public static function render(Config $config, string $kind, array $document, bool $embedded = false): string
    {
        $appName = self::escape(ApplicationNameService::resolve($config));
        $title = self::TITLES[$kind] ?? 'Document';
        $html = is_string($document['html'] ?? null) ? $document['html'] : '';
        $updated = self::date($document['updated_at'] ?? null);
        $updatedLine = $updated === null ? '' : '<p class="policy-updated">Updated <time datetime="'
            . self::escape((string) $document['updated_at']) . '">' . $updated . '</time></p>';
        $back = $embedded ? '' : '<p class="policy-back"><a class="secondary-button" href="/">← Back to ' . $appName . '</a></p>';
        $footer = PoweredBy::html($config);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <script src="/assets/js/theme.js"></script>
  <title>{$title} · {$appName}</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/rules.css">
</head>
<body>
  <main class="policy-page">
    <header class="policy-header">
      <p class="policy-eyebrow">{$appName}</p>
      <h1>{$title}</h1>
      {$updatedLine}
    </header>
    <article class="policy-content">
{$html}
    </article>
    {$back}
    {$footer}
  </main>
</body>
</html>
HTML;
    }

    private static function notFound(Config $config, string $kind): string
    {
        $appName = self::escape(ApplicationNameService::resolve($config));
        $title = self::TITLES[$kind] ?? 'Document';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <script src="/assets/js/theme.js"></script>
  <title>Not published · {$appName}</title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/rules.css">
</head>
<body>
  <main class="policy-page">
    <header class="policy-header">
      <p class="policy-eyebrow">{$appName}</p>
      <h1>{$title}</h1>
    </header>
    <p>{$appName} hasn't published this page.</p>
    <p class="policy-back"><a class="secondary-button" href="/">← Back to {$appName}</a></p>
  </main>
</body>
</html>
HTML;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('j F Y');
        } catch (Throwable) {
            return null;
        }
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
