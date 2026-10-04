<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Moderation\ModerationService;
use ChitChat\Moderation\MuteService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    // Reversible and audited, so no password confirmation (unlike roles and password resets).
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    $target = $payload['target_user_id'] ?? null;
    $reason = $payload['reason'] ?? '';
    $expiresAt = $payload['expires_at'] ?? null;
    if (!is_int($target)) {
        throw new ApiException(400, 'validation_error', 'target_user_id must be an integer.');
    }
    if (!is_string($reason)) {
        throw new ApiException(400, 'validation_error', 'reason must be a string.');
    }
    if ($expiresAt !== null && !is_string($expiresAt)) {
        throw new ApiException(400, 'validation_error', 'expires_at must be a string or null.');
    }

    (new ModerationService($pdo))->ban(
        $actor,
        $target,
        $reason,
        $expiresAt,
        Request::clientIp(),
    );

    // "Let the room know": a neutral line in the room the moderator is in.
    $roomId = Request::optionalInteger($payload, 'room_id');
    if (($payload['announce'] ?? false) === true && $roomId !== null) {
        $room = (new RoomRepository($pdo))->findForUser($roomId, $actor->id);
        $name = (new UserRepository($pdo))->findAuthenticatedById($target)?->username;
        if ($room !== null && RoomAuthorization::canModerate($actor, $room) && is_string($name)) {
            (new MessageService($pdo))->postNotice($roomId, sprintf(
                '%s was banned %s.',
                $name,
                MuteService::spanText($expiresAt === null ? null : new DateTimeImmutable($expiresAt)),
            ));
        }
    }

    return ApiResult::ok(['status' => 'banned']);
});
