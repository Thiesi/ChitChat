<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\TestScratch;
use ChitChat\Tests\Support\PhpHttpFixture;
use PHPUnit\Framework\TestCase;

final class RetryAfterResponseTest extends TestCase
{
    public function testHttpErrorsExposeMatchingRetryMetadataWithoutChangingOtherResponses(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $router = TestScratch::file('retry-after-router', '<?php require ' . $autoload . ';' . <<<'PHP'
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
Endpoint::run(Config::fromEnvironment(), static function (): ApiResult {
    $code = $_GET['code'] ?? 'rate_limited';
    if ($code === 'ok') {
        return ApiResult::ok(['status' => 'ok']);
    }
    if ($code === 'guest_limit_reached') {
        throw new ApiException(429, $code, 'Concurrent guest limit.');
    }
    if ($code === 'invalid_credentials') {
        throw new ApiException(401, $code, 'Invalid username or password.');
    }
    throw new ApiException(429, $code, 'Existing message.', retryAfterSeconds: 3601);
});
PHP, '.php');
        $log = TestScratch::file('retry-after-server-log');
        $process = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);

            foreach (['rate_limited', 'login_throttled', 'guest_limit_reached', 'invalid_credentials', 'ok'] as $code) {
                $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
                $body = file_get_contents('http://' . $address . '/?code=' . $code, false, $context);
                self::assertIsString($body);
                self::assertNotEmpty($http_response_header);
                $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($payload);
                self::assertContains('Cache-Control: no-store', $http_response_header);
                $retryHeaders = array_values(array_filter(
                    $http_response_header,
                    static fn (string $header): bool => str_starts_with(strtolower($header), 'retry-after:'),
                ));
                if ($code === 'rate_limited' || $code === 'login_throttled') {
                    self::assertStringContainsString('429', $http_response_header[0]);
                    self::assertSame(['Retry-After: 3601'], $retryHeaders);
                    self::assertSame([
                        'error' => ['code' => $code, 'message' => 'Existing message.', 'retry_after_seconds' => 3601],
                    ], $payload);
                } else {
                    self::assertSame([], $retryHeaders);
                    if ($code === 'ok') {
                        self::assertStringContainsString('200', $http_response_header[0]);
                        self::assertSame(['status' => 'ok'], $payload);
                    } else {
                        self::assertSame(['code', 'message'], array_keys($payload['error']));
                        self::assertSame($code, $payload['error']['code']);
                    }
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
