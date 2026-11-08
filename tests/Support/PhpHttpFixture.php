<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use PHPUnit\Framework\Assert;

/** Bounded startup synchronization for real PHP HTTP fixtures. */
final class PhpHttpFixture
{
    /** @param resource $process */
    public static function waitUntilReady($process, string $address, string $log): void
    {
        $ready = false;
        $deadline = hrtime(true) + 30_000_000_000;
        $serverStatus = proc_get_status($process);
        do {
            if (!$serverStatus['running']) {
                break;
            }
            $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
            if (is_resource($connection)) {
                fclose($connection);
                $ready = true;
                break;
            }
            $serverStatus = proc_get_status($process);
            usleep(50_000);
        } while (hrtime(true) < $deadline);
        Assert::assertTrue($ready, sprintf(
            'PHP fixture not ready at %s; process=%s; log=%s',
            $address, json_encode($serverStatus, JSON_THROW_ON_ERROR),
            file_get_contents($log) ?: '(empty)',
        ));
    }
}
