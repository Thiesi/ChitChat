<?php

declare(strict_types=1);

namespace ChitChat\Maintenance;

use ChitChat\Config;
use PDO;

/** @deprecated Use CleanupService directly. */
final class MaintenanceService
{
    private readonly CleanupService $cleanup;

    public function __construct(PDO $pdo, Config $config)
    {
        $this->cleanup = new CleanupService($pdo, $config);
    }

    /** @return array<string, int|bool> */
    public function run(bool $dryRun): array
    {
        return $this->cleanup->run($dryRun);
    }
}
