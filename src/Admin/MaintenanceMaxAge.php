<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Config;
use PDO;
use RuntimeException;

/**
 * How old the newest successful destructive maintenance run may be before System status calls
 * it overdue (#312). The Operational setting wins once saved; until then (or after it is
 * cleared), MAINTENANCE_MAX_AGE_HOURS is the fallback default.
 */
final class MaintenanceMaxAge
{
    public const MINIMUM = 1;
    public const MAXIMUM = 720;

    /** @return array{hours:int, source:'setting'|'environment'} */
    public static function resolve(?int $saved, ?Config $config): array
    {
        return $saved === null
            ? ['hours' => $config === null ? 26 : $config->maintenanceMaxAgeHours, 'source' => 'environment']
            : ['hours' => $saved, 'source' => 'setting'];
    }

    /** @return array{hours:int, source:'setting'|'environment'} */
    public static function read(PDO $pdo, Config $config): array
    {
        $statement = $pdo->query('SELECT maintenance_max_age_hours FROM system_settings WHERE id = 1');
        if ($statement === false) {
            throw new RuntimeException('Unable to read the maintenance max age.');
        }
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new RuntimeException('System settings are missing.');
        }

        return self::resolve($value === null ? null : (int) $value, $config);
    }
}
