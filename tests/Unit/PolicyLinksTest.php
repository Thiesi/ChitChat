<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Config;
use ChitChat\View\PolicyLinks;
use ChitChat\View\PoweredBy;
use PHPUnit\Framework\TestCase;

final class PolicyLinksTest extends TestCase
{
    protected function tearDown(): void
    {
        PolicyLinks::useDocuments(null);
    }

    public function testNothingConfiguredKeepsTheAttributionUnchanged(): void
    {
        PolicyLinks::useDocuments([]);

        self::assertSame(
            '<p class="powered-by">Powered by <a href="' . PoweredBy::REPOSITORY_URL . '">ChitChat</a></p>',
            PoweredBy::html(Config::fromEnvironment()),
        );
        self::assertSame(PoweredBy::html(Config::fromEnvironment()), PoweredBy::html());
    }

    public function testConfiguredDocumentsAppearInAFixedOrderBeforeTheAttribution(): void
    {
        PolicyLinks::useDocuments([
            PolicyLinks::LEGAL_NOTICE => ['source' => 'external', 'url' => 'https://example.org/imprint'],
            PolicyLinks::PRIVACY => ['source' => 'hosted', 'url' => 'https://chat.example/privacy.php'],
            PolicyLinks::RULES => ['source' => 'hosted', 'url' => 'https://chat.example/rules.php'],
        ]);

        $html = PoweredBy::html(Config::fromEnvironment());

        self::assertMatchesRegularExpression('/Community rules.*Privacy.*Legal notice.*Powered by/s', $html);
        self::assertStringContainsString('<a href="https://chat.example/rules.php">Community rules</a>', $html);
        self::assertStringContainsString('<a href="https://chat.example/privacy.php">Privacy</a>', $html);
        // Another site is marked visibly and for screen readers.
        self::assertStringContainsString('href="https://example.org/imprint" rel="noopener">Legal notice<span aria-hidden="true"> ↗</span><span class="visually-hidden"> (opens another site)</span>', $html);
    }

    public function testTheSignInFooterOffersAHiddenAccessibilityFeedbackSlotBeforeTheAttribution(): void
    {
        PolicyLinks::useDocuments([]);
        $slot = PoweredBy::feedbackSlot();

        // Hidden until accessibility-feedback.js fills it from session.php (#219).
        self::assertStringContainsString('data-accessibility-feedback data-feedback-separator hidden', $slot);
        // Empty, so a footer without the link reads exactly as before.
        self::assertStringEndsWith('hidden></span>', $slot);
        self::assertSame(
            '<p class="powered-by">' . $slot . 'Powered by <a href="' . PoweredBy::REPOSITORY_URL . '">ChitChat</a></p>',
            PoweredBy::html(Config::fromEnvironment(), accessibilityFeedback: true),
        );

        PolicyLinks::useDocuments([PolicyLinks::RULES => ['source' => 'hosted', 'url' => 'https://chat.example/rules.php']]);
        self::assertMatchesRegularExpression(
            '/Community rules.*data-accessibility-feedback.*Powered by/s',
            PoweredBy::html(Config::fromEnvironment(), accessibilityFeedback: true),
        );
        // Other pages' footers don't carry it.
        self::assertStringNotContainsString('data-accessibility-feedback', PoweredBy::html(Config::fromEnvironment()));
    }

    public function testUnconfiguredDocumentsAreSkippedAndUrlsAreEscaped(): void
    {
        PolicyLinks::useDocuments([
            PolicyLinks::RULES => ['source' => 'external', 'url' => 'https://rules.example/?a=1&b="2"'],
            PolicyLinks::PRIVACY => null,
            PolicyLinks::LEGAL_NOTICE => ['source' => 'external', 'url' => ''],
        ]);

        $html = PolicyLinks::footerHtml(Config::fromEnvironment());

        self::assertStringContainsString('href="https://rules.example/?a=1&amp;b=&quot;2&quot;"', $html);
        self::assertStringNotContainsString('Privacy', $html);
        self::assertStringNotContainsString('Legal notice', $html);
        self::assertNull(PolicyLinks::document(Config::fromEnvironment(), PolicyLinks::PRIVACY));
    }
}
