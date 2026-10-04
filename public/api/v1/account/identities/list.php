<?php

declare(strict_types=1);

use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $users = new UserRepository($pdo);
    $actor = SessionManager::requireUser($users);
    $service = new OidcService($pdo, $config);

    return ApiResult::ok([
        'providers' => $service->providers(),
        'identities' => $service->identities($actor->id),
        'has_password' => $users->hasPassword($actor->id),
    ]);
});
