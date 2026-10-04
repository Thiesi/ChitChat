<?php

declare(strict_types=1);

use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Forgets a provider sign-up that is waiting for its username.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    (new OidcService(Database::connect($config), $config))->cancelSignUp();

    return ApiResult::ok(['status' => 'cancelled']);
});
