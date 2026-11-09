<?php

declare(strict_types=1);

use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    header('Cache-Control: no-store');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUserOrGuest(new UserRepository($pdo));
    SessionManager::requireCsrf(Request::csrfHeader());
    (new RateLimiter($pdo, $config->rateLimits))->consume('sessions_management', 'user:' . $actor->id);
    Request::json();
    if (!$actor->guest) SessionManager::requirePrivilegedStepUp($actor, $config);
    $binding = SessionManager::loginBinding() ?? throw new ApiException(401, 'authentication_required', 'Sign in again.');
    $count = (new LoginSessionRepository($pdo))->revoke($actor, $binding['id'], $binding['hash'], null, Request::clientIp());
    return ApiResult::ok(['revoked_count' => $count]);
});
