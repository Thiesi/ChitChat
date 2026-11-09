<?php

declare(strict_types=1);
namespace ChitChat\View;

use InvalidArgumentException;

/**
 * The signed-in shell shared by every page (S2, #168): a skip link, then one
 * navigation landmark that is a rail on wide screens and CCM's bottom bar on
 * phones, then the page's own <main>. The pages stay separate documents;
 * each wraps its <main> in open() … close().
 *
 * The navigation starts hidden. shell.js shows it once the session is known:
 * members get Rooms · Direct · Activity · You, guests Rooms only, and staff
 * also get Stewarding (role-filtered, as the old Administration link was).
 * Badges start empty and are filled from data the pages already have.
 */
final class AppShell
{
    public const TABS = ['rooms', 'direct', 'activity', 'you', 'stewarding'];

    private const ITEMS = [
        'rooms' => ['href' => '/', 'label' => 'Rooms', 'icon' => 'nav-rooms'],
        'direct' => ['href' => '/messages.php', 'label' => 'Direct', 'icon' => 'nav-direct'],
        'activity' => ['href' => '/notifications.php', 'label' => 'Activity', 'icon' => 'nav-activity'],
        'you' => ['href' => '/account.php', 'label' => 'You', 'icon' => 'nav-you'],
    ];

    /**
     * @param string $tab the destination this page belongs to
     * @param string $skipTarget id of the element the skip link moves to
     * @param string $skipLabel what the skip link says
     * @param bool $pageCountsActivity whether this page's own scripts report the
     *        unread Activity count (so shell.js doesn't ask for it again)
     */
    public static function open(
        string $tab,
        string $skipTarget,
        string $skipLabel = 'Skip to content',
        bool $pageCountsActivity = false,
    ): string {
        if (!in_array($tab, self::TABS, true)) {
            throw new InvalidArgumentException('Unknown shell tab.');
        }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $skipTarget) !== 1) {
            throw new InvalidArgumentException('Invalid skip target.');
        }

        $html = '<a class="skip-link" href="#' . $skipTarget . '">' . self::escape($skipLabel) . '</a>'
            . '<div class="app-frame" data-tab="' . $tab . '"'
            . ($pageCountsActivity ? ' data-activity-source="page"' : '') . '>'
            . '<nav id="app-nav" class="app-nav" aria-label="Main" hidden><ul class="app-nav-list">';
        foreach (self::ITEMS as $key => $item) {
            $html .= '<li class="app-nav-entry" data-entry="' . $key . '"' . ($key === 'rooms' ? '' : ' data-members-only') . '>'
                . self::item($key, $item['href'], $item['label'], $item['icon'], $tab)
                . '</li>';
        }
        $html .= '</ul>'
            // Staff only; shell.js sets the destination (administration or the moderation queue).
            . '<div class="app-nav-steward" hidden>'
            . self::item('stewarding', '/admin.php', 'Stewarding', 'steward', $tab)
            . '</div></nav>';

        return $html;
    }

    public static function close(): string
    {
        return '</div>';
    }

    private static function item(string $key, string $href, string $label, string $icon, string $tab): string
    {
        $current = $key === $tab ? ' aria-current="page"' : '';
        $badge = $key === 'activity'
            ? '<span class="app-nav-badge" data-badge="activity" aria-hidden="true" hidden></span>'
            : '';

        return '<a class="app-nav-item" data-tab="' . $key . '" href="' . $href . '"' . $current . '>'
            . '<span class="app-nav-icon">' . self::icon($icon) . $badge . '</span>'
            . '<span class="app-nav-label">' . self::escape($label) . '</span>'
            . '</a>';
    }

    /** One of the shared Lantern icons (public/assets/icons), inline so it takes the text colour. */
    private static function icon(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/public/assets/icons/' . $name . '.svg';
        $svg = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if (!str_starts_with($svg, '<svg')) {
            return '';
        }

        return (string) preg_replace('/^\s*<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
