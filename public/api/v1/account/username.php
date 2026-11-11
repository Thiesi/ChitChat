<?php

declare(strict_types=1);

use ChitChat\Account\UsernameChangeService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Chooses a new username after a moderator required one (#164). Only while
// session.php reports user.username_change; not a general self-service rename.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('username_change', 'user:' . $actor->id);
    $user = (new UsernameChangeService($pdo))->choose($actor, Request::string($payload, 'username'), Request::clientIp());

    return ApiResult::ok(['user' => $user->toSessionArray()]);
});
