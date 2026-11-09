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
    public static function html(): string
    {
        return <<<'HTML'
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="icon" href="/assets/app-icons/favicon-32.png" type="image/png" sizes="32x32">
  <link rel="apple-touch-icon" href="/assets/app-icons/apple-touch-icon.png">
  <link rel="manifest" href="/manifest.php">
  <script type="module" src="/assets/js/push-lifecycle.js"></script>
  <meta name="theme-color" content="#FBF7F1" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#14111C" media="(prefers-color-scheme: dark)">
HTML;
    }
}
