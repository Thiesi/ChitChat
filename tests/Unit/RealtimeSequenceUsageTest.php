<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * StreamCursor's skip is safe only if realtime event ids are taken solely by INSERTs into
 * realtime_events, which hold RowExclusiveLock until they end (#281). Nothing may call
 * nextval on the sequence or reserve ids any other way.
 */
final class RealtimeSequenceUsageTest extends TestCase
{
    public function testOnlyStreamCursorReadsTheSequenceAndNothingDrawsFromIt(): void
    {
        $root = dirname(__DIR__, 2);
        $readers = [];
        foreach (['src', 'public', 'bin'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', ''], true)) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                self::assertDoesNotMatchRegularExpression('/nextval\s*\(\s*[\'"]realtime_events_id_seq/i', $source, $relative);
                self::assertDoesNotMatchRegularExpression('/setval\s*\(\s*[\'"]realtime_events_id_seq/i', $source, $relative);
                if (str_contains($source, 'realtime_events_id_seq')) {
                    $readers[] = $relative;
                }
            }
        }
        self::assertSame(['src/Realtime/StreamCursor.php'], $readers);
    }
}
