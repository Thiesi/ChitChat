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

    $target = $payload['target_user_id'] ?? null;
    $reason = $payload['reason'] ?? '';
    $expiresAt = $payload['expires_at'] ?? null;
    if (!is_int($target)) {
        throw new ApiException(400, 'validation_error', 'target_user_id must be an integer.');
    }
    if (!is_string($reason)) {
        throw new ApiException(400, 'validation_error', 'reason must be a string.');
    }
    if ($expiresAt !== null && !is_string($expiresAt)) {
        throw new ApiException(400, 'validation_error', 'expires_at must be a string or null.');
    }

    (new ModerationService($pdo))->ban(
        $actor,
        $target,
        $reason,
        $expiresAt,
        Request::clientIp(),
    );

    return ApiResult::ok(['status' => 'banned']);
});
