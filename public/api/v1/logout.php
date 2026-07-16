<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function (): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    SessionManager::logout();

    return ApiResult::ok(['status' => 'logged_out']);
});
