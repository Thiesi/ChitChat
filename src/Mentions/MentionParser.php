<?php

declare(strict_types=1);

namespace ChitChat\Mentions;

/** Keep the grammar/fallback in step with mention-matching.js and the shared fixture. */
final class MentionParser
{
    /** Complete lowercase @-words in first-appearance order, deduplicated.
     * @return list<string>
     */
    public static function tokens(string $body): array
    {
        if (preg_match_all('/(?<![A-Za-z0-9_.-])@([A-Za-z0-9][A-Za-z0-9_.-]{2,})/u', $body, $matches) < 1) {
            return [];
        }
        $seen = [];
        $tokens = [];
        foreach ($matches[1] as $raw) {
            $canonical = strtolower($raw);
            if (!isset($seen[$canonical]) && self::candidates($canonical) !== []) {
                $seen[$canonical] = true;
                $tokens[] = $canonical;
            }
        }
        return $tokens;
    }

    /** Longest valid username first; remove one trailing dot, hyphen or underscore per step.
     * @return list<string>
     */
    public static function candidates(string $raw): array
    {
        $token = strtolower($raw);
        $result = [];
        while (strlen($token) >= 3) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,31}\z/D', $token) === 1) {
                $result[] = $token;
            }
            if (preg_match('/[._-]$/D', $token) !== 1) {
                break;
            }
            $token = substr($token, 0, -1);
        }
        return $result;
    }

    /** Resolve only against names authorized for this context; broadcast is opt-in.
     * @param callable(string):bool $isKnown
     */
    public static function resolveToken(string $raw, callable $isKnown, bool $broadcast = false): ?string
    {
        foreach (self::candidates($raw) as $candidate) {
            if (($broadcast && in_array($candidate, ['room', 'here'], true)) || $isKnown($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    public static function containsBroadcastToken(string $body): bool
    {
        foreach (self::tokens($body) as $token) {
            if (self::resolveToken($token, static fn (string $candidate): bool => false, true) !== null) {
                return true;
            }
        }
        return false;
    }
}
