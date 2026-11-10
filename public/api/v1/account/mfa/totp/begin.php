<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    if (!\ChitChat\View\TotpPresentation::integrated()) {
        throw \ChitChat\Auth\Totp\TotpCipher::unavailable();
    }
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $service = new \ChitChat\Auth\Totp\TotpService($pdo, $config);
    return ApiResult::ok($service->begin($actor, Request::clientIp()));
});
