<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Moderation\ModerationService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);

    $target = $payload['target_user_id'] ?? null;
    if (!is_int($target)) {
        throw new ApiException(400, 'validation_error', 'target_user_id must be an integer.');
    }

    (new ModerationService($pdo))->resetPassword(
        $actor,
        $target,
        Request::string($payload, 'new_password'),
        Request::clientIp(),
    );

    return ApiResult::ok(['status' => 'password_reset']);
});
