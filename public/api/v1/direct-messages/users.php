<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $search = $_GET['search'] ?? '';
    if (!is_string($search)) {
        $search = '';
    }
    $limit = Request::optionalQueryInteger('limit') ?? 20;

    return ApiResult::ok([
        'users' => (new DirectMessageService($pdo))->searchUsers($actor, $search, $limit),
    ]);
});
