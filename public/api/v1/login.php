<?php

declare(strict_types=1);

use ChitChat\Auth\AuthService;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();

    $auth = new AuthService(Database::connect($config), $config);
    $user = $auth->login(
        Request::string($payload, 'username'),
        Request::string($payload, 'password'),
        Request::clientIp(),
    );
    SessionManager::login($user);

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toArray(),
    ]);
});
