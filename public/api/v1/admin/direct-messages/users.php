<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageInspectionService;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume(
        'admin_direct_message_user_search',
        'user:' . $actor->id,
    );
    $search = $_GET['search'] ?? '';
    if (!is_string($search)) {
        $search = '';
    }

    return ApiResult::ok([
        'users' => (new DirectMessageInspectionService($pdo, $config))->searchUsers(
            $actor,
            $search,
            Request::optionalQueryInteger('limit') ?? 20,
        ),
    ]);
});
