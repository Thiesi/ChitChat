<?php

declare(strict_types=1);

namespace ChitChat\Observability;

use Closure;
use ChitChat\Config;
use Throwable;

/**
 * Warnings that many PHP workers may raise at once, logged at most once per interval per kind
 * and instance (#286). FPM workers share no memory, so the last time is a small file in the
 * temporary directory, named per instance because several instances may share one host's
 * /tmp. Any file problem means "log anyway": this must never break the caller.
 */
final class RateLimitedLog
{
    /** @var Closure(string): void */
    private readonly Closure $sink;

    /** @param ?Closure(string): void $sink where lines go (error_log by default) */
    public function __construct(
        private readonly string $instanceKey,
        private readonly int $intervalSeconds = 60,
        private readonly ?string $directory = null,
        ?Closure $sink = null,
    ) {
        $this->sink = $sink ?? static function (string $line): void {
            error_log($line);
        };
    }

    public static function forInstance(Config $config): self
    {
        return new self(self::instanceKey($config->databaseHost, $config->databasePort, $config->databaseName));
    }

    /** A short key per installation: its database. */
    public static function instanceKey(string $host, int $port, string $database): string
    {
        return substr(hash('sha256', $host . '|' . $port . '|' . $database), 0, 12);
    }

    /** Logs $message unless a warning of this kind was logged within the interval. Returns whether it logged. */
    public function warn(string $kind, string $message): bool
    {
        if (!$this->due($kind)) {
            return false;
        }
        ($this->sink)($message);

        return true;
    }

    private function due(string $kind): bool
    {
        try {
            $directory = $this->directory ?? sys_get_temp_dir();
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'chitchat-' . $this->instanceKey . '-'
                . preg_replace('/[^a-z0-9_-]/', '', strtolower($kind)) . '.warn';
            clearstatcache(true, $path);
            $last = @filemtime($path);
            if ($last !== false && time() - $last < $this->intervalSeconds) {
                return false;
            }
            // Atomic: write a unique file, then rename it over the marker.
            $temporary = $path . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($temporary, (string) time()) !== false && !@rename($temporary, $path)) {
                @unlink($temporary);
            }
        } catch (Throwable) {
            // No usable temporary directory: log anyway.
        }

        return true;
    }
}
