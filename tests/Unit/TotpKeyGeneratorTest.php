<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\TemporaryPaths;
use PHPUnit\Framework\TestCase;

final class TotpKeyGeneratorTest extends TestCase
{
    use TemporaryPaths;

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();
        parent::tearDown();
    }

    public function testGeneratePrintsAnInstallableRingWithoutLoadingConfigurationOrWritingFiles(): void
    {
        $directory = $this->temporaryDirectory(true);
        file_put_contents($directory . '/.env', 'TOTP_ENCRYPTION_KEYS=do-not-replace');
        $pipes = [];
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/totp-key', 'generate', '--key-id=0'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory,
            ['APP_ENV' => 'production', 'DB_HOST' => 'does-not-exist.invalid', 'TOTP_ENCRYPTION_KEYS' => 'invalid-config-must-not-be-read']);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('', $errors);
        self::assertSame(1, preg_match("/\\ATOTP_ENCRYPTION_KEYS='([^']+)'\\nTOTP_ACTIVE_KEY_ID=0\\n\\z/D", $output, $matches));
        $ring = \ChitChat\Auth\Totp\TotpKeyRing::fromJson($matches[1], '0');
        self::assertSame(32, strlen($ring->key('0')));
        self::assertSame('TOTP_ENCRYPTION_KEYS=do-not-replace', file_get_contents($directory . '/.env'));
        self::assertSame(['.env'], array_values(array_diff(scandir($directory), ['.', '..'])));
    }
}