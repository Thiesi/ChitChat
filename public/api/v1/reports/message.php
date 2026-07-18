<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Moderation\ReportService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('message_report', 'user:' . $actor->id);

    $kind = Request::string($payload, 'message_kind');
    $service = new ReportService($pdo);
    $arguments = [
        $actor,
        Request::integer($payload, 'message_id'),
        Request::string($payload, 'category'),
        Request::optionalString($payload, 'details'),
        Request::clientIp(),
    ];
    $case = match ($kind) {
        'room' => $service->reportRoomMessage(...$arguments),
        'direct' => $service->reportDirectMessage(...$arguments),
        default => throw new ApiException(400, 'validation_error', 'message_kind must be room or direct.'),
    };

    return ApiResult::created(['case' => $case]);
});
