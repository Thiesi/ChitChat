<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Room\RoomService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $room = (new RoomService($pdo))->update(
        $actor,
        Request::integer($payload, 'room_id'),
        Request::string($payload, 'name'),
        Request::string($payload, 'info_line'),
        Request::string($payload, 'visibility'),
        Request::integer($payload, 'minimum_age'),
        Request::optionalInteger($payload, 'inactivity_timeout_seconds') ?? 0,
        Request::clientIp(),
    );

    return ApiResult::ok(['room' => $room->toArray()]);
});
