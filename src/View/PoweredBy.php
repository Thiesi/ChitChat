<?php

declare(strict_types=1);
namespace ChitChat\View;

use ChitChat\Config;

/**
 * The small line at the bottom of each page: the installation's own
 * documents (community rules, privacy policy, legal notice) when it has
 * configured them, then the attribution. The attribution names the software
 * deliberately, independent of the installation's configured name.
 */
final class PoweredBy
{
    public const REPOSITORY_URL = 'https://github.com/Thiesi/ChitChat';

    public static function html(?Config $config = null): string
    {
        $attribution = 'Powered by <a href="' . self::REPOSITORY_URL . '">ChitChat</a>!';
        $documents = $config === null ? '' : PolicyLinks::footerHtml($config);
        if ($documents === '') {
            return '<p class="powered-by">' . $attribution . '</p>';
        }

        return '<p class="powered-by"><span class="footer-documents">' . $documents . '</span>'
            . '<span class="footer-separator" aria-hidden="true"> · </span>' . $attribution . '</p>';
    }
}
