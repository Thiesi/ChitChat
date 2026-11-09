<?php

declare(strict_types=1);

namespace ChitChat\View;

/** Temporary controller seam; the visual lead replaces this body before release. */
final class NativeAuthPage
{
    public const INTEGRATED = false;

    public static function integrated(): bool
    {
        return self::INTEGRATED;
    }

    /** @param array<string,mixed> $view */
    public static function render(array $view): string
    {
        return '<!doctype html><html lang="en"><meta charset="utf-8"><title>Native sign-in</title><h1>'
            . htmlspecialchars((string) ($view['phase'] ?? 'disabled'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</h1></html>';
    }
}
