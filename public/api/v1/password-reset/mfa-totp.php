<?php

declare(strict_types=1);

use ChitChat\Auth\PasswordResetService;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Auth\SessionManager;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    return ApiResult::ok((new PasswordResetService(Database::connect($config), $config))->finishTotp(
        Request::string($payload, 'code'), Request::clientIp(),
    ));
});
