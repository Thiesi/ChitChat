<?php

declare(strict_types=1);

namespace ChitChat\Mentions;

final class MentionParser
{
    /**
     * Lowercase canonical @-tokens in first-appearance order, deduplicated.
     * Matches the same character class as ChitChat\Auth\Username::display(),
     * so every token is a syntactically valid username shape — 'room' and
     * 'here' included, since they are reserved broadcast keywords rather
     * than a distinct syntax.
     *
     * @return list<string>
     */
    public static function tokens(string $body): array
    {
        if (preg_match_all('/(?<![A-Za-z0-9_.-])@([A-Za-z0-9][A-Za-z0-9_.-]{2,31})/u', $body, $matches) < 1) {
            return [];
        }

        $seen = [];
        $tokens = [];
        foreach ($matches[1] as $raw) {
            $canonical = strtolower($raw);
            if (!isset($seen[$canonical])) {
                $seen[$canonical] = true;
                $tokens[] = $canonical;
            }
        }

        return $tokens;
    }

    public static function containsBroadcastToken(string $body): bool
    {
        foreach (self::tokens($body) as $token) {
            if ($token === 'room' || $token === 'here') {
                return true;
            }
        }

        return false;
    }
}
