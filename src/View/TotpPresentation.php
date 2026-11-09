<?php

declare(strict_types=1);

namespace ChitChat\View;

/** Enabled only after the enrollment, proof and management UI has been integrated. */
final class TotpPresentation
{
    public const INTEGRATED = false;

    public static function integrated(): bool
    {
        return self::INTEGRATED;
    }
}