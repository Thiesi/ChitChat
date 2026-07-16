<?php

declare(strict_types=1);

use ChitChat\Auth\AuthService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
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
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    $auth = new AuthService($pdo, $config);
    $user = $auth->changePassword(
        $actor,
        Request::string($payload, 'current_password'),
        Request::string($payload, 'new_password'),
        Request::clientIp(),
    );
    SessionManager::login($user);

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toArray(),
    ]);
});
