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
use ChitChat\Moderation\UserReportService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUserOrGuest(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('user_report', 'user:' . $actor->id);
    $context = $payload['context'] ?? null;
    if ($context !== null && !($context instanceof stdClass)) {
        throw new ApiException(400, 'validation_error', 'context must be an object or null.');
    }
    return ApiResult::created(['case' => (new UserReportService($pdo, $config))->report(
        actor: $actor,
        userId: Request::integer($payload, 'user_id'),
        category: Request::string($payload, 'category'),
        details: Request::optionalString($payload, 'details'),
        roomId: $context === null ? null : Request::integer(get_object_vars($context), 'room_id'),
        ipAddress: Request::clientIp(),
    )]);
});
