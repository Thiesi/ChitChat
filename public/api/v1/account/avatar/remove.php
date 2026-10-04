<?php

declare(strict_types=1);

use ChitChat\Account\AvatarService;
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
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    // Without user_id, your own; a global moderator may name someone else.
    $userId = Request::optionalInteger($payload, 'user_id') ?? $actor->id;
    (new AvatarService($pdo, $config))->remove($actor, $userId, Request::clientIp());

    return ApiResult::ok(['status' => 'removed']);
});
