<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The settled horizon (#281, #286) is safe only if ids are taken solely by INSERTs into the
 * table, which hold RowExclusiveLock until they end. Nothing may call nextval or setval on
 * these sequences or reserve ids any other way, and only the horizon code reads them.
 */
final class RealtimeSequenceUsageTest extends TestCase
{
    private const READERS = [
        'realtime_events_id_seq' => ['src/Realtime/SettledHorizon.php', 'src/Realtime/StreamCursor.php'],
        'room_messages_id_seq' => ['src/Realtime/SettledHorizon.php'],
    ];

    public function testOnlyTheHorizonCodeReadsTheSequencesAndNothingDrawsFromThem(): void
    {
        $root = dirname(__DIR__, 2);
        $readers = array_fill_keys(array_keys(self::READERS), []);
        foreach (['src', 'public', 'bin'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', ''], true)) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                foreach (array_keys(self::READERS) as $sequence) {
                    self::assertDoesNotMatchRegularExpression('/(nextval|setval)\s*\(\s*[\'"]' . $sequence . '/i', $source, $relative);
                    if (str_contains($source, $sequence)) {
                        $readers[$sequence][] = $relative;
                    }
                }
                self::assertDoesNotMatchRegularExpression('/pg_get_serial_sequence\s*\(\s*[\'"](realtime_events|room_messages)[\'"]/i', $source, $relative);
            }
        }
        foreach ($readers as $sequence => $files) {
            sort($files);
            self::assertSame(self::READERS[$sequence], $files, $sequence);
        }
    }
}
