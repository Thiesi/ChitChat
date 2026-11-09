<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class PublicImportsTest extends TestCase
{
    public function testGlobalPublicRoutesHaveNoWarningProducingNonCompoundImports(): void
    {
        $root = dirname(__DIR__, 2) . '/public';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        $violations = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);
            preg_match_all('/^use\s+(?:(?:function|const)\s+)?([^\s;]+)\s*;/mi', $source, $matches);
            foreach ($matches[1] as $target) {
                $name = ltrim($target, '\\');
                if (!str_contains($name, '\\')) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $violations[] = $relative . ': use ' . $target . ';';
                }
            }
        }
        self::assertSame([], $violations, 'No-op global imports emit warnings before JSON, binary, SSE or metrics output.');
    }
}
