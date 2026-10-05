<?php

declare(strict_types=1);

use ChitChat\Auth\GuestService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Ends a guest's session at once; the guest is signed out and leaves every room.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('moderation_action', 'user:' . $actor->id);

    (new GuestService($pdo))->endByModerator($actor, Request::integer($payload, 'user_id'), Request::clientIp());

    return ApiResult::ok(['status' => 'ended']);
});
