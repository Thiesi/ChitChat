<?php

declare(strict_types=1);

use ChitChat\Account\AvatarService;
use ChitChat\Account\IgnoreService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Moderation\ModerationOptions;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $userId = Request::queryInteger('user_id');

    return ApiResult::ok([
        'profile' => (new AvatarService($pdo, $config))->profile($actor, $userId) + [
            'ignored' => (new IgnoreService($pdo))->isIgnoring($actor->id, $userId),
            // Moderation actions for the room the card was opened in, and everywhere.
            'moderation' => (new ModerationOptions($pdo))->for($actor, $userId, Request::optionalQueryInteger('room_id')),
        ],
        'avatars_available' => AvatarService::available(),
    ]);
});
