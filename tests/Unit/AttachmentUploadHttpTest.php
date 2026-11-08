<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\TestScratch;
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
$config = Config::fromEnvironment();
Endpoint::run($config, static function () use ($config): ApiResult {
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024 && ($_POST !== [] || $_FILES !== [])) {
        throw new RuntimeException('PHP did not discard the oversized multipart body.');
    }
    AttachmentUploadLimits::fromConfig($config)->requireBodyFits($_SERVER['CONTENT_LENGTH'] ?? null);
    return ApiResult::ok(['fields' => count($_POST), 'files' => count($_FILES)]);
});
PHP, '.php');
        $log = TestScratch::file('upload-limit-server-log');
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'post_max_size=1K',
                '-d', 'upload_max_filesize=512', '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            $ready = false;
            $deadline = microtime(true) + 10;
            do {
                $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(50_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($ready, (string) file_get_contents($log));
            foreach (['1', str_repeat('x', 2048)] as $value) {
                $body = "--upload-test\r\nContent-Disposition: form-data; name=\"room_id\"\r\n\r\n"
                    . $value . "\r\n--upload-test--\r\n";
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
                if ($value === '1') {
                    self::assertStringContainsString('200', $http_response_header[0]);
                    self::assertSame(['fields' => 1, 'files' => 0], $payload);
                } else {
                    self::assertStringContainsString('413', $http_response_header[0]);
                    self::assertSame('attachment_too_large', $payload['error']['code']);
                    self::assertSame(512, $payload['error']['max_bytes']);
                }
            }
        } finally {
            proc_terminate($process);
            proc_close($process);
            TestScratch::remove($router);
            TestScratch::remove($log);
        }
    }
}
