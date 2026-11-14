<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;
use PHPUnit\Framework\TestCase;

/**
 * #357: a HEAD download never opens the file. A router hands AttachmentDownloadResponse a path
 * that doesn't exist: HEAD still answers 200 with the stored size, because it never opens the
 * file, while GET fails at the open with 410. (authorizeDownload only stats the file, for
 * GET and HEAD alike.)
 */
final class AttachmentDownloadResponseHttpTest extends TestCase
{
    public function testHeadSendsTheHeadersWithoutOpeningTheFile(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, (string) $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $missing = var_export(TestScratch::path('never-created-attachment'), true);
        $router = TestScratch::file('download-head-router', '<?php require ' . $autoload . '; $missing = ' . $missing . ';' . <<<'PHP'
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;
use ChitChat\Upload\AttachmentDownloadResponse;
$preview = ($_GET['variant'] ?? '') === 'preview';
try {
    AttachmentDownloadResponse::send(
        ['path' => $missing, 'name' => 'report.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 12345, 'sha256' => str_repeat('a', 64), 'previewable' => false],
        false,
        $preview,
        Request::isHead(),
    );
} catch (ApiException $exception) {
    http_response_code($exception->status);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['code' => $exception->errorCode]]);
}
PHP, '.php');
        $log = TestScratch::file('download-head-server-log');
        $process = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            [$status, $headers, $body] = self::raw($address, 'HEAD', '/download');
            self::assertStringContainsString(' 200', $status, $body);
            self::assertStringContainsString("\ncontent-length: 12345", $headers);
            self::assertStringContainsString("\ncontent-type: application/pdf", $headers);
            self::assertStringContainsString("\ncontent-disposition: attachment; filename=\"report.pdf\"", $headers);
            self::assertSame('', $body, 'No body on the wire.');
            // The 304 path never opens the file either.
            self::assertStringContainsString(' 304', self::raw($address, 'HEAD', '/download?variant=preview', '"' . str_repeat('a', 64) . '"')[0]);
            // GET opens the file, and the missing file answers 410 there.
            [$getStatus, , $getBody] = self::raw($address, 'GET', '/download');
            self::assertStringContainsString(' 410', $getStatus, $getBody);
            self::assertStringContainsString('attachment_storage_missing', $getBody);
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
    private static function raw(string $address, string $method, string $path, ?string $ifNoneMatch = null): array
    {
        $socket = stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, (string) $errorMessage);
        stream_set_timeout($socket, 10);
        fwrite($socket, $method . ' ' . $path . " HTTP/1.1\r\nHost: " . $address . "\r\n"
            . ($ifNoneMatch === null ? '' : 'If-None-Match: ' . $ifNoneMatch . "\r\n")
            . "Connection: close\r\n\r\n");
        $raw = (string) stream_get_contents($socket);
        fclose($socket);
        $end = strpos($raw, "\r\n\r\n");
        self::assertNotFalse($end, $raw);
        $lines = explode("\r\n", substr($raw, 0, $end));
        $status = (string) array_shift($lines);
        return [$status, "\n" . strtolower(implode("\n", $lines)), substr($raw, $end + 4)];
    }
}
