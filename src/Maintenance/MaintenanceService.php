<?php

declare(strict_types=1);

namespace ChitChat\Maintenance;

use ChitChat\Config;
use PDO;

/** @deprecated Use MaintenanceCoordinator directly. */
final class MaintenanceService
{
    private readonly MaintenanceCoordinator $coordinator;

    public function __construct(PDO $pdo, Config $config)
    {
        $this->coordinator = new MaintenanceCoordinator($pdo, $config);
    }

    /** @return array<string, int|bool> */
    public function run(bool $dryRun): array
    {
        return $this->coordinator->run($dryRun);
    }
}
