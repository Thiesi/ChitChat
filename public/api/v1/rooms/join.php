<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Room\RoomReadService;
use ChitChat\Room\RoomService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUserOrGuest(new UserRepository($pdo));
    $room = (new RoomService($pdo))->join(
        $actor,
        Request::integer($payload, 'room_id'),
        Request::clientIp(),
    );
    // The read position the join started (#268), as the room list reports it, so a client
    // can replace its list entry without asking for the list again.
    $reads = (new RoomReadService($pdo))->forUser($actor->id)[$room->id] ?? null;

    return ApiResult::ok(['room' => $room->toArray() + [
        'unread_count' => $reads['unread_count'] ?? 0,
        'last_read_message_id' => $reads['last_read_message_id'] ?? null,
        'unread_mention_count' => $reads['unread_mention_count'] ?? 0,
        'unread_through_message_id' => $reads['unread_through_message_id'] ?? null,
    ]]);
});
