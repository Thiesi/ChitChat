<?php

declare(strict_types=1);

use ChitChat\Tests\Support\TestScratch;

require dirname(__DIR__) . '/vendor/autoload.php';

// Refuse to add to scratch that has already grown past the limit
// (docs/operations/local-disk-hygiene.md).
foreach ([TestScratch::worktreeProblem(dirname(__DIR__)), TestScratch::tempProblem()] as $problem) {
    if ($problem !== null) {
        fwrite(STDERR, $problem . PHP_EOL);
        exit(1);
    }
}

// Tests remove their own files; this catches whatever a failed or aborted test left behind.
register_shutdown_function([TestScratch::class, 'removeRoot']);
