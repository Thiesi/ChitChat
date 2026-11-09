<?php

declare(strict_types=1);
namespace ChitChat\View;

/**
 * Icons, the web app manifest and the browser theme colour for every page's
 * <head> (S0, #167). The mark and icons come from ChitChat Mobile (see
 * public/assets/icons/PROVENANCE.md); the theme colours are Lantern's grounds
 * (Paper and Night ink), so browser chrome matches the page around it.
 */
final class HeadAssets
{
    /** @param array{favicon_url:string,mark_url:string,apple_touch_url:string}|null $icon */
    public static function html(?array $icon = null): string
    {
        $custom = '';
        if ($icon !== null) {
            $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $custom = '  <link rel="icon" href="' . $e($icon['favicon_url']) . '" type="image/png" sizes="32x32">'
                . "\n  <link rel=\"apple-touch-icon\" href=\"" . $e($icon['apple_touch_url']) . "\">\n";
        }
        $html = <<<'HTML'
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="/assets/app-icons/favicon-32.png" type="image/png" sizes="32x32">
  <link rel="apple-touch-icon" href="/assets/app-icons/apple-touch-icon.png">
  <link rel="manifest" href="/manifest.php">
  <script type="module" src="/assets/js/push-lifecycle.js"></script>
  <meta name="theme-color" content="#FBF7F1" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#14111C" media="(prefers-color-scheme: dark)">
HTML;
        if ($icon === null) {
            return $html;
        }
        // Do not leave the default SVG as a competing favicon when a custom icon is selected.
        return $custom . substr($html, (int) strpos($html, '  <link rel="manifest"'));
    }
}
