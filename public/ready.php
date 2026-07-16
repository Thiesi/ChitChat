<?php

declare(strict_types=1);

use ChitChat\Database;
use ChitChat\Http\JsonResponse;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $pdo = Database::connect($config);
    $statement = $pdo->query('SELECT 1');
    if ($statement === false) {
        throw new RuntimeException('Database readiness query could not be prepared.');
    }
    $statement->fetchColumn();

    JsonResponse::send(['status' => 'ready']);
} catch (\Throwable $exception) {
    $payload = ['status' => 'not_ready'];
    if ($config->debug) {
        $payload['error'] = $exception->getMessage();
    }

    JsonResponse::send($payload, 503);
}
