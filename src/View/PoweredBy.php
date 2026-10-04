<?php

declare(strict_types=1);
namespace ChitChat\View;

/**
 * The small attribution line at the bottom of each page. It names the
 * software deliberately, independent of the installation's configured name.
 */
final class PoweredBy
{
    public const REPOSITORY_URL = 'https://github.com/Thiesi/ChitChat';

    public static function html(): string
    {
        return '<p class="powered-by">Powered by <a href="' . self::REPOSITORY_URL . '">ChitChat</a>!</p>';
    }
}
