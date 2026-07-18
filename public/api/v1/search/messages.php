<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Search\MessageSearchService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume(
        'message_search',
        'user:' . $actor->id,
    );

    return ApiResult::ok((new MessageSearchService($pdo))->search(
        actor: $actor,
        queryInput: Request::string($payload, 'query'),
        scope: Request::optionalString($payload, 'scope') ?? 'all',
        limit: Request::optionalInteger($payload, 'limit') ?? 25,
        offset: Request::optionalInteger($payload, 'offset') ?? 0,
    ));
});
