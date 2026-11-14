<?php

declare(strict_types=1);
namespace ChitChat\View;

use ChitChat\Config;

/**
 * The small line at the bottom of each page: the installation's own
 * documents (community rules, privacy policy, legal notice) when it has
 * configured them, then the attribution. The attribution names the software
 * deliberately, independent of the installation's configured name.
 *
 * The sign-in screens also offer "Report an accessibility problem" (#219)
 * before the attribution: a hidden slot that accessibility-feedback.js fills
 * from session.php and shows, unless the operator switched the link off.
 */
final class PoweredBy
{
    public const REPOSITORY_URL = 'https://github.com/Thiesi/ChitChat';

    private const SEPARATOR = '<span class="footer-separator" aria-hidden="true"> · </span>';

    public static function html(?Config $config = null, bool $accessibilityFeedback = false): string
    {
        $attribution = 'Powered by <a href="' . self::REPOSITORY_URL . '">ChitChat</a>';
        $documents = $config === null ? '' : PolicyLinks::footerHtml($config);
        $feedback = $accessibilityFeedback ? self::feedbackSlot() : '';
        if ($documents === '') {
            return '<p class="powered-by">' . $feedback . $attribution . '</p>';
        }

        return '<p class="powered-by"><span class="footer-documents">' . $documents . '</span>'
            . self::SEPARATOR . $feedback . $attribution . '</p>';
    }

    /**
     * An empty, hidden slot: the browser puts the link and its separator before the
     * attribution, so nothing of it is in the page while the link is off.
     */
    public static function feedbackSlot(): string
    {
        return '<span class="footer-feedback" data-accessibility-feedback data-feedback-separator hidden></span>';
    }
}
