<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;
use PHPUnit\Framework\TestCase;

/**
 * #345: a page that fails for real (an uncaught exception after it queued headers and
 * printed part of itself, or a true fatal error) shows the 500 status page through a
 * real PHP web server, with the installation's configured name and clean headers.
 */
final class StatusPageHttpTest extends TestCase
{
    public function testAFailingPageShowsTheStatusPageWithCleanHeaders(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, (string) $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $router = TestScratch::file('status-page-router', '<?php require ' . $autoload . ';' . <<<'PHP'
use ChitChat\Config;
use ChitChat\View\PageErrors;
// The suite runs with APP_DEBUG on, which keeps PHP's own output; a production page doesn't.
putenv('APP_DEBUG=0');
putenv('APP_NAME=Garden Chat');
$config = Config::fromEnvironment();
PageErrors::install($config);
$mode = $_GET['mode'] ?? '';
if ($mode === 'throw') {
    header('Location: /elsewhere');
    header('ETag: "stale"');
    ob_start();
    echo '<!doctype html><main>half a page';
    throw new RuntimeException('The page failed.');
}
if ($mode === 'fatal') {
    ini_set('memory_limit', '16M');
    $big = str_repeat('x', 64 * 1024 * 1024);
}
echo 'fine';
PHP, '.php');
        $log = TestScratch::file('status-page-server-log');
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'output_buffering=4096', '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            foreach (['throw', 'fatal'] as $mode) {
                [$status, $headers, $body] = self::get($address, '/page.php?mode=' . $mode);
                self::assertStringContainsString('500', $status, $mode . ': ' . $body);
                self::assertStringContainsString('<h1>Something went wrong</h1>', $body, $mode);
                self::assertStringContainsString('· Garden Chat</title>', $body, $mode);
                self::assertStringNotContainsString('half a page', $body, $mode);
                self::assertStringContainsString('content-security-policy', $headers, $mode);
                self::assertStringContainsString('cache-control: no-store', $headers, $mode);
                self::assertStringContainsString('content-type: text/html', $headers, $mode);
                self::assertStringNotContainsString("\nlocation:", $headers, $mode);
                self::assertStringNotContainsString("\netag:", $headers, $mode);
            }
            // A page that doesn't fail is left alone, and so is a request that isn't for a page.
            self::assertSame('fine', self::get($address, '/page.php')[2]);
            self::assertStringNotContainsString('Something went wrong', self::get($address, '/page.php?mode=throw', 'application/json')[2]);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            TestScratch::remove($router);
            TestScratch::remove($log);
        }
    }

    /** @return array{0: string, 1: string, 2: string} status line, lower-cased headers, body */
    private static function get(string $address, string $path, string $accept = 'text/html,application/xhtml+xml'): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => 'Accept: ' . $accept,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]);
        $body = (string) file_get_contents('http://' . $address . $path, false, $context);
        $headers = $http_response_header;

        return [(string) ($headers[0] ?? ''), "\n" . strtolower(implode("\n", array_slice($headers, 1))), $body];
    }
}
