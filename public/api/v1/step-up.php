<?php

declare(strict_types=1);

use ChitChat\Auth\PrivilegedStepUpService;
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

    return ApiResult::ok([
        'privileged_step_up' => (new PrivilegedStepUpService($pdo, $config))->verify(
            actor: $actor,
            password: Request::string($payload, 'password'),
            ipAddress: Request::clientIp(),
        ),
    ]);
});
