<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Realtime\BroadcastService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $service = new BroadcastService($pdo);
    $roomId = Request::optionalInteger($payload, 'room_id');
    $message = Request::string($payload, 'message');

    $event = $roomId === null
        ? $service->global($actor, $message)
        : $service->room($actor, $roomId, $message);

    return ApiResult::created(['event' => $event->toArray()]);
});
