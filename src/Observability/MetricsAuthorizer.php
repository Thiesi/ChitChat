<?php

declare(strict_types=1);

namespace ChitChat\Observability;

final class MetricsAuthorizer
{
    public static function accepts(string $configuredToken, string $authorizationHeader): bool
    {
        if ($configuredToken === '') {
            return false;
        }

        $prefix = 'Bearer ';
        $authorizationHeader = trim($authorizationHeader);
        if (!str_starts_with($authorizationHeader, $prefix)) {
            return false;
        }

        $provided = substr($authorizationHeader, strlen($prefix));
        return $provided !== '' && hash_equals($configuredToken, $provided);
    }
}
