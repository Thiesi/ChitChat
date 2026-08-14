<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Mentions\MentionParser;
use ChitChat\Realtime\PingCommand;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $roomId = Request::integer($payload, 'room_id');
    $body = Request::string($payload, 'body');
    $replyToMessageId = Request::optionalInteger($payload, 'reply_to_message_id');
    $ping = PingCommand::parse($body);
    $rateLimiter = new RateLimiter($pdo, $config->rateLimits);
    $rateLimiter->consume(
        $ping === null ? 'room_send' : 'room_ping',
        'user:' . $actor->id,
    );
    if ($ping === null && MentionParser::containsBroadcastToken($body)) {
        $rateLimiter->consume('room_broadcast_mention', 'user:' . $actor->id);
    }

    if ($ping !== null) {
        $event = (new PingService($pdo))->send(
            $actor,
            $roomId,
            $ping['username'],
            $ping['message'],
        );

        return ApiResult::created(['ping' => $event->toArray()]);
    }

    $message = (new MessageService($pdo))->send($actor, $roomId, $body, $replyToMessageId);

    return ApiResult::created(['message' => $message]);
});
