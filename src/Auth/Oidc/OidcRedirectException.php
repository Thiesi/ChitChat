<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

use RuntimeException;

/**
 * A sign-in that cannot continue. The browser arrives from the provider
 * through a full-page redirect, so the reason is shown on the page the
 * browser is sent back to instead of being returned as JSON.
 */
final class OidcRedirectException extends RuntimeException
{
    public function __construct(public readonly string $returnTo, string $message)
    {
        parent::__construct($message);
    }
}
