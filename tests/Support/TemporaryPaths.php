<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

/**
 * Temporary files and folders for a test, all under TestScratch's fixed root.
 * Call removeTemporaryPaths() from tearDown().
 */
trait TemporaryPaths
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    /** A fresh folder path under the scratch root; created only when $create is true. */
    private function temporaryDirectory(bool $create = false, string $label = 'dir'): string
    {
        $path = $create ? TestScratch::directory($label) : TestScratch::path($label);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryFile(string $content = '', string $label = 'file'): string
    {
        $path = TestScratch::file($label, $content);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function removeTemporaryPaths(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            TestScratch::remove($path);
        }
        $this->temporaryPaths = [];
    }
}
