<?php

declare(strict_types=1);

use ChitChat\Http\JsonResponse;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';

JsonResponse::send([
    'status' => 'ok',
    'application' => $config->applicationName,
    'version' => $config->applicationVersion,
]);
