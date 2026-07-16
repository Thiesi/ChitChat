<?php

declare(strict_types=1);

use ChitChat\Admin\RoomAdminService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $users = (new RoomAdminService($pdo))->searchInvitable(
        $actor,
        Request::queryInteger('room_id'),
        Request::queryString('search'),
        Request::optionalQueryInteger('limit') ?? 20,
    );

    return ApiResult::ok(['users' => $users]);
});
