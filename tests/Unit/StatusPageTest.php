<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\View\PageErrors;
use ChitChat\View\StatusPage;
use PHPUnit\Framework\TestCase;

/** S7 (#173): the error and not-found pages, and when a failure gets one. */
final class StatusPageTest extends TestCase
{
    public function testNotFoundSaysWhatHappenedWhatStillWorksAndTheWayBack(): void
    {
        $html = StatusPage::render(404, 'Garden Chat');
        self::assertStringContainsString('<title>Page not found · Garden Chat</title>', $html);
        self::assertStringContainsString('<h1>There’s nothing here</h1>', $html);
        self::assertStringContainsString('Your rooms and messages are where you left them.', $html);
        self::assertStringContainsString('<a class="primary-button" href="/">Go to Rooms</a>', $html);
        self::assertStringNotContainsString('Try again', $html);
    }

    public function testServerErrorOffersTryAgainAsAPlainLinkToTheSameAddress(): void
    {
        $html = StatusPage::render(500, null, '/messages.php?with=3');
        self::assertStringContainsString('<h1>Something went wrong</h1>', $html);
        self::assertStringContainsString('<a class="secondary-button" href="/messages.php?with=3">Try again</a>', $html);
        // No inline script: the CSP allows none.
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('<script>', $html);
        // Without the installation's name (the database may be down) it still renders.
        self::assertStringContainsString('· ChitChat</title>', $html);
    }

    public function testTheNameAndAddressAreEscapedAndAnUnknownStatusIsAServerError(): void
    {
        $html = StatusPage::render(418, '<b>Chat</b>', '/"><script>');
        self::assertStringContainsString('&lt;b&gt;Chat&lt;/b&gt;', $html);
        self::assertStringContainsString('data-status="418"', $html);
        self::assertStringContainsString('<h1>Something went wrong</h1>', $html);
        self::assertStringContainsString('href="/&quot;&gt;&lt;script&gt;"', $html);
        self::assertStringContainsString('<h1>You can’t open this page</h1>', StatusPage::render(403));
    }

    public function testAHalfRenderedPageStillInTheBufferIsDropped(): void
    {
        // PHPUnit keeps its own buffer; the page's start above it, as output_buffering=4096 would.
        $base = ob_get_level();
        ob_start();
        echo '<!doctype html><main>half a page';
        ob_start();
        echo 'and a nested part';
        PageErrors::discardBufferedOutput($base);
        self::assertSame($base, ob_get_level());
        $this->expectOutputString('');
    }

    public function testTryAgainOnlyEverLinksBackToThisSite(): void
    {
        self::assertSame('/messages.php?with=3', StatusPage::localAddress('/messages.php?with=3'));
        self::assertSame('/', StatusPage::localAddress('//evil.example/x'));
        self::assertSame('/', StatusPage::localAddress('/\evil.example/x'));
        self::assertSame('/', StatusPage::localAddress('https://evil.example/'));
        self::assertSame('/', StatusPage::localAddress(null));
        // Browsers drop tabs and line breaks before reading a URL: these are //evil.example.
        self::assertSame('/', StatusPage::localAddress("/\t/evil.example/x"));
        self::assertSame('/', StatusPage::localAddress("/\n/evil.example/x"));
    }

    public function testOnlyABrowserAskingForAPageGetsTheErrorPage(): void
    {
        // The script that runs decides (#345): a doubled slash doesn't hide an API request.
        self::assertFalse(PageErrors::wantsPage(['REQUEST_URI' => '//api/v1/session.php', 'SCRIPT_NAME' => '/api/v1/session.php', 'HTTP_ACCEPT' => 'text/html']));
        self::assertFalse(PageErrors::wantsPage(['REQUEST_URI' => '/api//v1/session.php', 'HTTP_ACCEPT' => 'text/html']));
        self::assertTrue(PageErrors::wantsPage(['REQUEST_URI' => '/admin.php', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml']));
        self::assertFalse(PageErrors::wantsPage(['REQUEST_URI' => '/api/v1/session.php', 'HTTP_ACCEPT' => 'text/html']));
        self::assertFalse(PageErrors::wantsPage(['REQUEST_URI' => '/health.php', 'HTTP_ACCEPT' => 'application/json']));
        self::assertFalse(PageErrors::wantsPage(['REQUEST_URI' => '/ready.php']));
    }
}
