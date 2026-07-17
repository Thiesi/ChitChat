<?php

declare(strict_types=1);

use ChitChat\Database;
use ChitChat\Observability\PrometheusEncoder;
use ChitChat\Observability\SystemStatusService;
use Throwable;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || $config->metricsBearerToken === '') {
    http_response_code(404);
    exit;
}

$authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
$prefix = 'Bearer ';
$provided = str_starts_with($authorization, $prefix) ? substr($authorization, strlen($prefix)) : '';
if ($provided === '' || !hash_equals($config->metricsBearerToken, $provided)) {
    http_response_code(401);
    header('WWW-Authenticate: Bearer realm="ChitChat metrics"');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unauthorized\n";
    exit;
}

try {
    $status = (new SystemStatusService(Database::connect($config), $config))->snapshot();
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    header('Cache-Control: no-store');
    echo PrometheusEncoder::encode($status);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Metrics unavailable\n";
}
