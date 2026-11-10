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
    $service = new \ChitChat\Auth\Totp\TotpKeySettings($pdo, $config);
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $enabled = $payload['database_key_enabled'] ?? null;
    if (!is_bool($enabled)) throw new \ChitChat\Http\ApiException(400, 'validation_error', 'database_key_enabled must be a boolean.');
    return ApiResult::ok(['totp' => $service->update($actor, $enabled, Request::clientIp())]);
});
