<?php

declare(strict_types=1);

namespace ChitChat\Search;

/**
 * A plain-text excerpt around the first match of a search result, with match offsets instead of
 * markup (#283). Matches come from PostgreSQL: ts_headline marks them with two private-use
 * sentinels, which this class turns into ranges. Lengths are in characters (code points), as the
 * 320-character limit always was; offsets are in UTF-16 code units, which is how JavaScript and
 * Kotlin index strings.
 */
final class SearchExcerpt
{
    public const START = "\u{E000}";
    public const STOP = "\u{E001}";
    public const MAX = 320;
    /** Characters of context before the first match, at most. */
    public const CONTEXT = 100;

    /** Removes the sentinels from a body, so a person can't forge a mark. */
    public static function clean(string $body): string
    {
        return str_replace([self::START, self::STOP], '', $body);
    }

    /**
     * The marked ranges of a ts_headline result, in characters of the cleaned body. Null when the
     * text without marks isn't exactly the cleaned body (then no offsets are trusted).
     *
     * @return ?list<array{0:int, 1:int}> [start, length] pairs
     */
    public static function marks(string $headline, string $cleanBody): ?array
    {
        $ranges = [];
        $position = 0;
        $open = null;
        foreach (mb_str_split($headline, 1, 'UTF-8') as $char) {
            if ($char === self::START) {
                $open = $position;
            } elseif ($char === self::STOP) {
                if ($open !== null && $position > $open) {
                    $ranges[] = [$open, $position - $open];
                }
                $open = null;
            } else {
                $position++;
            }
        }

        return self::clean($headline) === $cleanBody ? $ranges : null;
    }

    /**
     * Keeps the marks whose word is one of the included lexemes (the simple configuration's
     * lexeme is the lower-cased word). With no lexeme list, every mark is kept.
     *
     * @param list<array{0:int, 1:int}> $ranges
     * @param ?list<string> $lexemes
     * @return list<array{0:int, 1:int}>
     */
    public static function included(string $cleanBody, array $ranges, ?array $lexemes): array
    {
        if ($lexemes === null) {
            return $ranges;
        }
        $wanted = array_fill_keys($lexemes, true);

        return array_values(array_filter(
            $ranges,
            static fn (array $range): bool => isset($wanted[mb_strtolower(mb_substr($cleanBody, $range[0], $range[1], 'UTF-8'), 'UTF-8')]),
        ));
    }

    /**
     * @param list<array{0:int, 1:int}> $ranges match ranges in characters of $cleanBody, in order
     * @return array{excerpt:string, matches:list<array{start:int, length:int}>}
     */
    public static function build(string $cleanBody, array $ranges): array
    {
        [$chars, $map] = self::normalize($cleanBody);
        $count = count($chars);
        $normalized = [];
        foreach ($ranges as [$start, $length]) {
            if (!isset($map[$start], $map[$start + $length - 1])) {
                continue;
            }
            $normalized[] = [$map[$start], $map[$start + $length - 1] + 1];
        }

        if ($count <= self::MAX) {
            return self::result($chars, 0, $count, '', '', $normalized);
        }
        if ($normalized === []) {
            // No match to centre on: the first characters, as before.
            $text = rtrim(implode('', array_slice($chars, 0, self::MAX - 1)));

            return ['excerpt' => $text . '…', 'matches' => []];
        }

        [$matchStart, $matchEnd] = $normalized[0];
        $start = max(0, $matchStart - self::CONTEXT);
        $start = self::wordStart($chars, $start, $matchStart);
        $prefix = $start > 0 ? '… ' : '';
        $end = $start + self::MAX - mb_strlen($prefix) - 2;
        if ($end >= $count) {
            // Near the end: use the whole budget, ending at the end.
            $end = $count;
            $start = max(0, $count - (self::MAX - 2));
            $start = self::wordStart($chars, $start, $matchStart);
            $prefix = $start > 0 ? '… ' : '';

            return self::result($chars, $start, $end, $prefix, '', $normalized);
        }
        // Cut back to a word boundary, but never into the first match.
        while ($end > $matchEnd && $chars[$end - 1] !== ' ' && $chars[$end] !== ' ') {
            $end--;
        }
        while ($end > $start && $chars[$end - 1] === ' ') {
            $end--;
        }

        return self::result($chars, $start, $end, $prefix, ' …', $normalized);
    }

    /**
     * Whitespace normalised as excerpts always were (trimmed, runs of \s as one space), with the
     * position of every original character in the normalised text.
     *
     * @return array{0: list<string>, 1: array<int, int>}
     */
    private static function normalize(string $text): array
    {
        $chars = [];
        $map = [];
        $pendingSpace = false;
        foreach (mb_str_split($text, 1, 'UTF-8') as $index => $char) {
            if (preg_match('/\s/u', $char) === 1) {
                $pendingSpace = $chars !== [];
                $map[$index] = count($chars);
                continue;
            }
            if ($pendingSpace) {
                $chars[] = ' ';
                $pendingSpace = false;
            }
            $map[$index] = count($chars);
            $chars[] = $char;
        }

        return [$chars, $map];
    }

    /** @param list<string> $chars */
    private static function wordStart(array $chars, int $start, int $limit): int
    {
        if ($start === 0) {
            return 0;
        }
        while ($start < $limit && $chars[$start - 1] !== ' ') {
            $start++;
        }
        while ($start < $limit && $chars[$start] === ' ') {
            $start++;
        }

        return $start;
    }

    /**
     * @param list<string> $chars
     * @param list<array{0:int, 1:int}> $ranges [start, end) in $chars
     * @return array{excerpt:string, matches:list<array{start:int, length:int}>}
     */
    private static function result(array $chars, int $start, int $end, string $prefix, string $suffix, array $ranges): array
    {
        $matches = [];
        $offset = self::utf16Length($prefix);
        $cursor = $start;
        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            if ($rangeStart < $start || $rangeEnd > $end) {
                continue;
            }
            $offset += self::utf16Length(implode('', array_slice($chars, $cursor, $rangeStart - $cursor)));
            $length = self::utf16Length(implode('', array_slice($chars, $rangeStart, $rangeEnd - $rangeStart)));
            $matches[] = ['start' => $offset, 'length' => $length];
            $offset += $length;
            $cursor = $rangeEnd;
        }

        return [
            'excerpt' => $prefix . implode('', array_slice($chars, $start, $end - $start)) . $suffix,
            'matches' => $matches,
        ];
    }

    private static function utf16Length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
