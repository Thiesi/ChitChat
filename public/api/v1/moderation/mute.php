<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Moderation\MuteService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Mutes someone in one room (room_id) or everywhere (room_id null).
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $reason = $payload['reason'] ?? '';
    $expiresAt = $payload['expires_at'] ?? null;
    if (!is_string($reason)) {
        throw new ApiException(400, 'validation_error', 'reason must be a string.');
    }
    if ($expiresAt !== null && !is_string($expiresAt)) {
        throw new ApiException(400, 'validation_error', 'expires_at must be a date and time or null.');
    }
    try {
        $until = $expiresAt === null ? null : new DateTimeImmutable($expiresAt);
    } catch (Exception) {
        throw new ApiException(400, 'validation_error', 'expires_at must be a date and time or null.');
    }
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    return ApiResult::ok(['mute' => (new MuteService($pdo))->mute(
        $actor,
        Request::integer($payload, 'user_id'),
        Request::optionalInteger($payload, 'room_id'),
        $until,
        $reason,
        ($payload['announce'] ?? false) === true,
        Request::clientIp(),
    )]);
});
