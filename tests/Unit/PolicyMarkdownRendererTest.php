<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\Policy\PolicyMarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class PolicyMarkdownRendererTest extends TestCase
{
    public function testRestrictedFormattingAndPageHeadingOwnership(): void
    {
        $html = (new PolicyMarkdownRenderer())->convert("# Rules\n\nBe **kind** and *clear*.\n\n- One\n- Two\n\n---\n\n[Help](https://example.org/help)");
        self::assertStringContainsString('<h2>Rules</h2>', $html);
        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringContainsString('<strong>kind</strong>', $html);
        self::assertStringContainsString('<em>clear</em>', $html);
        self::assertStringContainsString('<ul>', $html);
        self::assertStringContainsString('<hr', $html);
        self::assertStringContainsString('href="https://example.org/help"', $html);
    }

    public function testRawHtmlImagesAndNonWhitelistedLinksCannotBecomeActiveContent(): void
    {
        $html = (new PolicyMarkdownRenderer())->convert(<<<'MD'
<script>alert(1)</script>

<img src="https://tracker.test/" onerror="alert(1)">

![tracking](https://tracker.test/a.png)

[bad](javascript:alert%281%29) [data](data:text/html;base64,AA) [file](file:///etc/passwd)

[ftp](ftp://example.org) [relative](/account.php) [mail](mailto:help@example.org)
MD);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        foreach (['javascript:', 'data:', 'file:', 'ftp:', '/account.php'] as $url) {
            self::assertStringNotContainsString('href="' . $url, $html);
        }
        self::assertStringContainsString('href="mailto:help@example.org"', $html);
    }

    public function testPreviewAndPublishShareAnExplicitByteLimit(): void
    {
        $renderer = new PolicyMarkdownRenderer();
        self::assertNotEmpty($renderer->convert(str_repeat('a', PolicyMarkdownRenderer::MAX_BYTES)));
        $this->expectException(ApiException::class);
        $renderer->convert(str_repeat('a', PolicyMarkdownRenderer::MAX_BYTES + 1));
    }

    public function testInvalidUtf8IsRejectedBeforeParsing(): void
    {
        $this->expectException(ApiException::class);
        (new PolicyMarkdownRenderer())->convert("invalid\xff");
    }
}
