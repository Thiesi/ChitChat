<?php

declare(strict_types=1);
namespace ChitChat\View;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Config;
use ChitChat\InstallationIcon\IconService;
use InvalidArgumentException;

/**
 * The installation's lockup on the signed-out pages (S3, #169; #227, web only):
 * the installation's own icon, unchanged, with its name beside it as the page
 * heading. "on ChitChat" follows whenever the name isn't ChitChat, so a
 * renamed installation always says what it runs on.
 *
 * Stacked on wide sign-in screens and horizontal elsewhere (auth.css decides
 * by width; `horizontal` keeps it horizontal at every width). The icon is
 * decorative: the name beside it is the text.
 */
final class Lockup
{
    public static function html(Config $config, string $headingId, bool $horizontal = false): string
    {
        $icon = IconService::resolve($config);
        return self::render(
            ApplicationNameService::resolve($config),
            is_string($icon['mark_url'] ?? null) ? $icon['mark_url'] : '/assets/app-icons/icon-192.png',
            $headingId,
            $horizontal,
        );
    }

    /** The markup for a name and an icon URL (the default mark, a #226 preset or an upload). */
    public static function render(string $name, string $src, string $headingId, bool $horizontal = false): string
    {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $headingId) !== 1) {
            throw new InvalidArgumentException('Invalid lockup heading id.');
        }
        $sub = self::showsPlatform($name)
            ? '<p class="lockup-sub">on ChitChat</p>'
            : '';

        return '<div class="lockup' . ($horizontal ? ' lockup-horizontal' : '') . '">'
            . '<span class="lockup-mark">'
            . '<img src="' . self::escape($src) . '" alt="" width="96" height="96" decoding="async">'
            . '</span>'
            . '<div class="lockup-text">'
            . '<h1 id="' . $headingId . '" class="lockup-name">' . self::escape($name) . '</h1>'
            . $sub
            . '</div>'
            . '</div>';
    }

    /** "on ChitChat" for every name but ChitChat itself (ignoring case and spaces). */
    public static function showsPlatform(string $name): bool
    {
        return strcasecmp(trim($name), 'ChitChat') !== 0;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
