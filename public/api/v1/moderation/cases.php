<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Moderation\ReportService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('moderation_queue', 'user:' . $actor->id);

    return ApiResult::ok((new ReportService($pdo))->cases(
        actor: $actor,
        status: Request::optionalQueryString('status') ?? 'open',
        beforeId: Request::optionalQueryInteger('before_id'),
        limit: Request::optionalQueryInteger('limit') ?? 50,
    ));
});
