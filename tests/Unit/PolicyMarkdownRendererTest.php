<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\Policy\PolicyMarkdownRenderer;
use PHPUnit\Framework\TestCase;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Renderer\ChildNodeRendererInterface;

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

    public function testLinkAttributesEscapeQuotesAngleBracketsAndAmpersands(): void
    {
        $renderer = new PolicyMarkdownRenderer();
        $children = $this->createMock(ChildNodeRendererInterface::class);
        $children->method('renderNodes')->willReturn('x');
        // Exercise raw node URLs as well as the parser, which can percent-encode them first.
        self::assertSame(
            '<a href="https://a.example/?q=&quot;onmouseover=alert(1)&amp;lt=&lt;" rel="noopener noreferrer">x</a>',
            (string) $renderer->render(new Link('https://a.example/?q="onmouseover=alert(1)&lt=<'), $children),
        );
        $html = $renderer->convert('[x](https://a.example/?q="onmouseover=alert(1))' . "\n\n"
            . '[x](https://a.example/?q=a&other=b)');
        self::assertSame(2, preg_match_all('/<a href="[^"<>]*" rel="noopener noreferrer">x<\/a>/', $html));
        self::assertStringNotContainsString('"onmouseover=', $html);
        self::assertStringContainsString('q=a&amp;other=b', $html);
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
