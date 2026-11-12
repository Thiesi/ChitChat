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

// Hands a room to another member, or lets an administrator take it over (#273).
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $room = (new RoomService($pdo))->transferOwnership(
        $actor,
        Request::integer($payload, 'room_id'),
        Request::integer($payload, 'target_user_id'),
        Request::clientIp(),
    );

    return ApiResult::ok(['room' => $room->toArray()]);
});
