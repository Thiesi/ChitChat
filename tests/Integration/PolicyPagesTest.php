<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Policy\PolicyService;
use ChitChat\View\PolicyLinks;
use ChitChat\View\PolicyPage;
use ChitChat\View\PoweredBy;

/**
 * The page side of #160 against the real policy service: the footer links
 * and the public document pages read what administrators publish.
 */
final class PolicyPagesTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        PolicyLinks::useDocuments(null);
        parent::tearDown();
    }

    public function testNothingPublishedLeavesTheFooterAndPagesAsBefore(): void
    {
        $config = $this->configWith(['applicationOrigin' => 'https://chat.test']);
        PolicyLinks::useDocuments(null);

        self::assertSame('', PolicyLinks::footerHtml($config));
        self::assertNull(PolicyLinks::document($config, PolicyLinks::RULES));
        self::assertStringNotContainsString('footer-documents', PoweredBy::html($config));
    }

    public function testPublishedDocumentsReachTheFooterAndTheHostedPage(): void
    {
        $config = $this->configWith(['applicationOrigin' => 'https://chat.test']);
        $root = (new AuthService($this->pdo, $config))->register('Root', 'a very secure password', '127.0.0.1');
        $policies = new PolicyService($this->pdo, $config);
        $policies->update($root, 'rules', 'hosted', "# Be kind\n\nTreat people well. <script>alert(1)</script>", null, true, 'First rules', '127.0.0.1');
        $policies->update($root, 'legal_notice', 'external', null, 'https://example.org/imprint', false, null, '127.0.0.1');
        PolicyLinks::useDocuments(null);

        $footer = PolicyLinks::footerHtml($config);
        self::assertStringContainsString('<a href="https://chat.test/rules.php">Community rules</a>', $footer);
        self::assertStringContainsString('href="https://example.org/imprint" rel="noopener">Legal notice', $footer);
        self::assertStringNotContainsString('Privacy', $footer);

        $rules = PolicyLinks::document($config, PolicyLinks::RULES);
        self::assertNotNull($rules);
        self::assertSame('hosted', $rules['source']);
        $page = PolicyPage::render($config, PolicyLinks::RULES, $rules);
        // The page owns h1; the document's own h1 renders as h2, and raw HTML stays escaped.
        self::assertStringContainsString('<h1>Community rules</h1>', $page);
        self::assertStringContainsString('<h2>Be kind</h2>', $page);
        self::assertStringNotContainsString('<script>alert(1)</script>', $page);
        self::assertStringContainsString('← Back to ', $page);
        self::assertStringNotContainsString('← Back to ', PolicyPage::render($config, PolicyLinks::RULES, $rules, true));
        self::assertNull(PolicyLinks::document($config, PolicyLinks::PRIVACY));
    }

    public function testExternalRulesAreAnExternalLinkWithoutHostedText(): void
    {
        $config = $this->configWith(['applicationOrigin' => 'https://chat.test']);
        $root = (new AuthService($this->pdo, $config))->register('Root', 'a very secure password', '127.0.0.1');
        (new PolicyService($this->pdo, $config))->update($root, 'rules', 'external', null, 'https://rules.example/terms', true, null, '127.0.0.1');
        PolicyLinks::useDocuments(null);

        $rules = PolicyLinks::document($config, PolicyLinks::RULES);
        self::assertSame('external', $rules['source'] ?? null);
        self::assertSame('https://rules.example/terms', $rules['url']);
        self::assertNull($rules['html']);
        self::assertStringContainsString('href="https://rules.example/terms" rel="noopener">Community rules', PolicyLinks::footerHtml($config));
    }
}
