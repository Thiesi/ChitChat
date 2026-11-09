<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\PhpHttpFixture;
use ChitChat\Tests\Support\TestScratch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeAndroidDalHttpTest extends TestCase
{
    /** @return iterable<string,array{string,int}> */
    public static function configurations(): iterable
    {
        yield 'default-empty' => ['[]', 200];
        yield 'configured' => [json_encode([
            ['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))],
        ], JSON_THROW_ON_ERROR), 200];
        yield 'invalid-trust-fails-closed' => ['[{"package":"de.example.app","sha256_cert_fingerprint":"invalid-private-fixture"}]', 500];
    }

    #[DataProvider('configurations')]
    public function testPublicDalNeedsNeitherDatabaseNorSessionAndUsesExactHttpSemantics(string $apps, int $expected): void
    {
        $root = dirname(__DIR__, 2);
        $router = TestScratch::file('native-dal-router');
        file_put_contents($router, '<?php if (parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) === "/.well-known/assetlinks.json") { require '
            . var_export($root . '/public/assetlinks.php', true) . '; return true; } return false;');
        $log = TestScratch::file('native-dal-log');
        $sessions = TestScratch::directory('native-dal-sessions');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, $error);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);
        $environment = array_merge(getenv(), ['APP_ENV' => 'test', 'APP_DEBUG' => '0',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_SSLMODE' => 'disable',
            'WEBAUTHN_RP_ID' => '', 'WEBAUTHN_ORIGIN' => '', 'NATIVE_ANDROID_APPS' => $apps]);
        unset($environment['PHP_CLI_SERVER_WORKERS']);
        $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessions,
            '-S', $address, '-t', $root . '/public', $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, $environment);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            PhpHttpFixture::waitUntilReady($process, $address, $log);
            $url = 'http://' . $address . '/.well-known/assetlinks.json';
            [$status, $body, $headers] = $this->request($url);
            self::assertSame($expected, $status);
            self::assertStringStartsWith('application/json', $headers['content-type'] ?? '');
            self::assertArrayNotHasKey('set-cookie', $headers);
            self::assertArrayNotHasKey('location', $headers);
            self::assertSame([], array_diff(scandir($sessions) ?: [], ['.', '..']), 'No session file may be created.');
            if ($expected === 500) {
                self::assertSame('internal_error', json_decode($body, true, 8, JSON_THROW_ON_ERROR)['error']['code']);
                self::assertStringContainsString('no-store', $headers['cache-control'] ?? '');
                self::assertStringNotContainsString('invalid-private-fixture', $body);
                self::assertStringNotContainsString('invalid-private-fixture', (string) file_get_contents($log));
                return;
            }
            self::assertSame('public, max-age=300', $headers['cache-control']);
            $document = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            if ($apps === '[]') {
                self::assertSame([], $document);
            } else {
                self::assertSame(['delegate_permission/common.get_login_creds'], $document[0]['relation']);
                self::assertSame('de.networkxxiii.chitchat', $document[0]['target']['package_name']);
            }
            [$headStatus, $headBody, $headHeaders] = $this->request($url, 'HEAD');
            self::assertSame(200, $headStatus);
            self::assertSame('', $headBody);
            self::assertSame((string) strlen($body), $headHeaders['content-length']);
            [$methodStatus, , $methodHeaders] = $this->request($url, 'POST');
            self::assertSame(405, $methodStatus);
            self::assertSame('GET, HEAD', $methodHeaders['allow']);
            self::assertStringContainsString('no-store', $methodHeaders['cache-control']);
            self::assertArrayNotHasKey('set-cookie', $methodHeaders);
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process);
            proc_close($process);
            TestScratch::remove($router);
            TestScratch::remove($log);
            TestScratch::remove($sessions);
        }
    }

    /** @return array{int,string,array<string,string>} */
    private function request(string $url, string $method = 'GET'): array
    {
        $headers = [];
        $curl = curl_init($url);
        self::assertNotFalse($curl);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            }]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        self::assertIsString($body, curl_error($curl));
        unset($curl);
        return [$status, $body, $headers];
    }
}
