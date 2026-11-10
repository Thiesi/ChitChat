<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $service = new \ChitChat\Auth\Totp\TotpCeremonyService($pdo, $config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    return ApiResult::ok($service->stepUp($actor, Request::string($payload, 'code'), Request::clientIp()));
});
