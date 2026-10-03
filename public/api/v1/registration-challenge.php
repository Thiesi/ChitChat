<?php

declare(strict_types=1);

use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $challenge = (new RegistrationProtectionService(Database::connect($config), $config))->challenge();

    return ApiResult::ok([
        'challenge' => $challenge->issue($_SESSION, time()),
    ]);
});
