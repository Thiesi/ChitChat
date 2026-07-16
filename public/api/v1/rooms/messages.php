<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Room\MessageService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $beforeId = Request::optionalQueryInteger('before_id');
    $limit = Request::optionalQueryInteger('limit') ?? 50;
    $messages = (new MessageService($pdo))->history(
        $actor,
        Request::queryInteger('room_id'),
        $beforeId,
        $limit,
    );

    return ApiResult::ok(['messages' => $messages]);
});
