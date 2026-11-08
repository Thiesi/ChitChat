<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\TestScratch;
use ChitChat\Tests\Support\PhpHttpFixture;
use PHPUnit\Framework\TestCase;

final class AttachmentUploadHttpTest extends TestCase
{
    public function testRealPhpDiscardedMultipartBodyReturnsSizeErrorWithLimit(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $router = TestScratch::file('upload-limit-router', '<?php require ' . $autoload . ';' . <<<'PHP'
use ChitChat\Config;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Upload\AttachmentUploadLimits;
use ChitChat\Upload\AttachmentFileStore;
use ChitChat\Upload\IncomingFile;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Tests\Support\PhpHttpFixture;
putenv('ATTACHMENT_STORAGE_PATH=' . TestScratch::directory('upload-limit-storage'));
$config = Config::fromEnvironment();
Endpoint::run($config, static function () use ($config): ApiResult {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 131072 && ($_POST !== [] || $_FILES !== [])) {
        throw new RuntimeException('PHP did not discard the oversized multipart body.');
    }
    $limits = AttachmentUploadLimits::fromConfig($config);
    $limits->requireBodyFits($_SERVER['CONTENT_LENGTH'] ?? null);
    $fileBytes = 0;
    if (isset($_FILES['file'])) {
        $stored = (new AttachmentFileStore($config))->store(IncomingFile::fromGlobal('file', $limits->maxBytes));
        $fileBytes = $stored['size_bytes'];
        unlink($stored['path']);
    }
    return ApiResult::ok(['fields' => count($_POST), 'files' => count($_FILES), 'file_bytes' => $fileBytes, 'max_bytes' => $limits->maxBytes]);
});
PHP, '.php');
        $log = TestScratch::file('upload-limit-server-log');
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'post_max_size=128K',
                '-d', 'upload_max_filesize=256K', '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);

            $field = "--upload-test\r\nContent-Disposition: form-data; name=\"room_id\"\r\n\r\n1\r\n";
            $fileHeader = "--upload-test\r\nContent-Disposition: form-data; name=\"file\"; filename=\"sample.txt\"\r\nContent-Type: text/plain\r\n\r\n";
            $fileContent = str_repeat("ChitChat upload fixture.\n", 6000);
            foreach ([
                [$field . "--upload-test--\r\n", 200, 0],
                [$field . $fileHeader . substr($fileContent, 0, 65_536) . "\r\n--upload-test--\r\n", 200, 65_536],
                [$field . $fileHeader . substr($fileContent, 0, 70_000) . "\r\n--upload-test--\r\n", 413, null],
                [$field . $fileHeader . substr($fileContent, 0, 131_072) . "\r\n--upload-test--\r\n", 413, null],
            ] as [$body, $status, $fileBytes]) {
                $context = stream_context_create(['http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: multipart/form-data; boundary=upload-test',
                    'content' => $body,
                    'ignore_errors' => true,
                    'timeout' => 5,
                ]]);
                $response = file_get_contents('http://' . $address . '/', false, $context);
                self::assertIsString($response);
                self::assertNotEmpty($http_response_header);
                $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                self::assertStringContainsString((string) $status, $http_response_header[0], $response);
                if ($status === 200) {
                    self::assertSame([
                        'fields' => 1, 'files' => $fileBytes === 0 ? 0 : 1,
                        'file_bytes' => $fileBytes, 'max_bytes' => 65_536,
                    ], $payload);
                } else {
                    self::assertSame('attachment_too_large', $payload['error']['code']);
                    self::assertSame(65_536, $payload['error']['max_bytes']);
                }
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
            TestScratch::remove($router);
            TestScratch::remove($log);
        }
    }
}
