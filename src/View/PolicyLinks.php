<?php

declare(strict_types=1);
namespace ChitChat\View;

use ChitChat\Config;
use Throwable;

/**
 * The installation's public documents as the pages need them (#160): the
 * footer links on every page and the documents behind /rules.php and
 * /privacy.php. This is the only page-side place that reads them, so the
 * service it reads from can change without touching the templates.
 *
 * A document is an array with `source` ('hosted' or 'external'), `url`,
 * `html` (hosted only, already rendered and sanitised by the server),
 * `version`, `updated_at` and `change_summary`; a missing document is null.
 */
final class PolicyLinks
{
    public const RULES = 'rules';
    public const PRIVACY = 'privacy';
    public const LEGAL_NOTICE = 'legal_notice';

    private const LABELS = [
        self::RULES => 'Community rules',
        self::PRIVACY => 'Privacy',
        self::LEGAL_NOTICE => 'Legal notice',
    ];

    /** @var array<string, array<string, mixed>|null>|null */
    private static ?array $cache = null;

    /**
     * One document, or null when the installation has not configured it or
     * the documents cannot be read (pages then simply show no link).
     *
     * @return array<string, mixed>|null
     */
    public static function document(Config $config, string $kind): ?array
    {
        return self::all($config)[$kind] ?? null;
    }

    /** The footer links for every configured document, in a fixed order. */
    public static function footerHtml(Config $config): string
    {
        $documents = self::all($config);
        $links = [];
        foreach (self::LABELS as $kind => $label) {
            $url = $documents[$kind]['url'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $external = ($documents[$kind]['source'] ?? 'hosted') === 'external' || $kind === self::LEGAL_NOTICE;
            $links[] = self::link($url, $label, $external);
        }

        return implode('<span class="footer-separator" aria-hidden="true"> · </span>', $links);
    }

    /** @return array<string, array<string, mixed>|null> */
    private static function all(Config $config): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        try {
            self::$cache = self::read($config);
        } catch (Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    /**
     * Reads the documents from the policy service.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private static function read(Config $config): array
    {
        // Rebase checkpoint: read PolicyService::publicDocument() for each kind
        // once the #160 backend lands. Until then nothing is configured.
        unset($config);

        return [];
    }

    private static function link(string $url, string $label, bool $external): string
    {
        $href = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!$external) {
            return '<a href="' . $href . '">' . $label . '</a>';
        }

        return '<a href="' . $href . '" rel="noopener">' . $label
            . '<span aria-hidden="true"> ↗</span><span class="visually-hidden"> (opens another site)</span></a>';
    }

    /**
     * Lets tests supply documents without a database; null reads them again.
     *
     * @param array<string, array<string, mixed>|null>|null $documents
     */
    public static function useDocuments(?array $documents): void
    {
        self::$cache = $documents;
    }
}
