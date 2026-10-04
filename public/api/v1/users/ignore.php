<?php

declare(strict_types=1);

use ChitChat\Account\IgnoreService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Ignore someone in rooms, or stop ignoring them.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $ignored = $payload['ignored'] ?? null;
    if (!is_bool($ignored)) {
        throw new ApiException(400, 'validation_error', 'ignored must be true or false.');
    }
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $service = new IgnoreService($pdo);
    $service->setIgnored($actor, Request::integer($payload, 'user_id'), $ignored);

    return ApiResult::ok(['ignored_user_ids' => $service->ignoredBy($actor->id)]);
});
