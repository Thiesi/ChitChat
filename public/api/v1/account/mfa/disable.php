<?php

declare(strict_types=1);

use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $user = (new MfaService($pdo, $config))->disable($actor, Request::clientIp());
    SessionManager::login($user);

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
        'disabled' => true,
    ]);
});
