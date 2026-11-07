<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\TestScratch;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TestScratchTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TestScratch::directory('scratch-guard');
    }

    protected function tearDown(): void
    {
        TestScratch::remove($this->root);
    }

    public function testScratchLivesUnderOneFixedFolderInTheTempFolder(): void
    {
        $expected = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . TestScratch::ROOT_NAME;

        self::assertSame($expected, TestScratch::root());
        self::assertStringStartsWith($expected . DIRECTORY_SEPARATOR, TestScratch::path('x'));
        self::assertNotSame(TestScratch::path('x'), TestScratch::path('x'));
    }

    public function testRemoveDeletesWholeTreesAndReportsSuccess(): void
    {
        mkdir($this->root . '/a/b', 0700, true);
        file_put_contents($this->root . '/a/b/file.txt', 'x');
        file_put_contents($this->root . '/top.txt', 'x');

        self::assertTrue(TestScratch::remove($this->root . '/a'));
        self::assertDirectoryDoesNotExist($this->root . '/a');
        self::assertFileExists($this->root . '/top.txt');
        self::assertTrue(TestScratch::remove($this->root . '/missing'));
    }

    public function testRemoveDeletesALinkButNeverItsTarget(): void
    {
        $target = TestScratch::directory('scratch-guard-target');
        file_put_contents($target . '/keep.txt', 'keep');
        try {
            if (!@symlink($target, $this->root . '/link')) {
                self::markTestSkipped('Symbolic links are unavailable here.');
            }
            TestScratch::remove($this->root);

            self::assertFileExists($target . '/keep.txt');
            self::assertDirectoryDoesNotExist($this->root);
        } finally {
            TestScratch::remove($target);
        }
    }

    public function testCountingStopsJustPastTheLimit(): void
    {
        mkdir($this->root . '/nested', 0700, true);
        for ($i = 0; $i < 5; $i++) {
            file_put_contents("{$this->root}/nested/{$i}.txt", 'x');
        }

        self::assertSame(5, TestScratch::countFiles($this->root));
        self::assertSame(3, TestScratch::countFiles($this->root, 2));
    }

    public function testWorktreeGuardRefusesOnlyAboveTheLimit(): void
    {
        mkdir($this->root . '/test-results', 0700, true);
        for ($i = 0; $i < 3; $i++) {
            file_put_contents("{$this->root}/test-results/{$i}.png", 'x');
        }

        self::assertNull(TestScratch::worktreeProblem($this->root, 3));
        self::assertStringContainsString('composer clean-scratch', (string) TestScratch::worktreeProblem($this->root, 2));
    }

    public function testTempGuardCountsOnlyThisProjectsEntries(): void
    {
        $temp = $this->root . '/temp';
        mkdir($temp . '/chitchat-tests/run', 0700, true);
        mkdir($temp . '/chitchat-release-rehearsal', 0700, true);
        mkdir($temp . '/someone-else', 0700, true);
        file_put_contents($temp . '/chitchat-tests/run/a', 'x');
        file_put_contents($temp . '/chitchat-release-rehearsal/b', 'x');
        file_put_contents($temp . '/someone-else/c', 'x');
        file_put_contents($temp . '/someone-else/d', 'x');

        self::assertSame(2, TestScratch::tempFileCount($temp));
        self::assertNull(TestScratch::tempProblem($temp, 2));
        self::assertStringContainsString('--temp', (string) TestScratch::tempProblem($temp, 1));
    }

    /**
     * Tests reach the temp folder only through TestScratch, so nothing lands
     * outside its fixed, cleaned folder under a name that changes every run.
     */
    public function testTestsDoNotCreateTemporaryFilesOutsideTestScratch(): void
    {
        $tests = dirname(__DIR__);
        $allowed = [realpath($tests . '/Support/TestScratch.php'), realpath(__FILE__)];
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tests, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getRealPath(), $allowed, true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/\b(sys_get_temp_dir|tempnam|tmpfile)\s*\(/', $source, $match) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($tests) + 1) . ': ' . $match[1] . '()';
            }
        }

        self::assertSame([], $offenders, 'Use ChitChat\Tests\Support\TestScratch (or the TemporaryPaths trait) instead.');
    }
}
