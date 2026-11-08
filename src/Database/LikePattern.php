<?php

declare(strict_types=1);

namespace ChitChat\Database;

/** Bound case-insensitive prefix for SQL LIKE with an explicit backslash ESCAPE. */
final class LikePattern
{
    public static function prefix(string $input): string
    {
        return strtr(strtolower($input), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }
}
