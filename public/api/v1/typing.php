<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Realtime\TypingService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

// "I am typing" in a room (room_id) or a direct conversation (recipient_user_id).
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $roomId = Request::optionalInteger($payload, 'room_id');
    $recipientId = Request::optionalInteger($payload, 'recipient_user_id');
    if (($roomId === null) === ($recipientId === null)) {
        throw new ApiException(400, 'validation_error', 'Send either room_id or recipient_user_id.');
    }
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUserOrGuest(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('typing', 'user:' . $actor->id);
    $typing = new TypingService($pdo);

    return ApiResult::ok([
        'signalled' => $roomId !== null
            ? $typing->inRoom($actor, $roomId)
            : $typing->inConversation($actor, (int) $recipientId),
    ]);
});
