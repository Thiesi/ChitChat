<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

/** A real isolated PHP session; callers run in a separate PHPUnit process. */
final class TestSession
{
    private string $directory;

    public function start(): void
    {
        $this->directory = TestScratch::directory('session');
        session_save_path($this->directory);
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        if (!session_start()) {
            throw new \RuntimeException('Could not start the test session.');
        }
        $_SESSION = [];
    }

    public function stop(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        TestScratch::remove($this->directory);
    }
}
