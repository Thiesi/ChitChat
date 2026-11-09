<?php

declare(strict_types=1);

use ChitChat\Admin\UserDirectoryService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume(
        'admin_user_search',
        'user:' . $actor->id,
    );
    $page = (new UserDirectoryService($pdo))->page(
        $actor,
        Request::optionalQueryString('search') ?? '',
        Request::optionalQueryInteger('after_id') ?? 0,
        Request::optionalQueryInteger('limit') ?? 50,
        Request::optionalQueryString('filter'),
        Request::optionalQueryString('sort') ?? 'id',
        Request::optionalQueryString('cursor'),
    );

    return ApiResult::ok($page);
});
