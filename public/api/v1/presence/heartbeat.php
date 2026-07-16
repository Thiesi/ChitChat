<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Presence\PresenceService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $presence = (new PresenceService($pdo, $config))->heartbeat(
        $actor,
        Request::string($payload, 'connection_id'),
        Request::optionalInteger($payload, 'room_id'),
        Request::boolean($payload, 'interacted'),
    );

    return ApiResult::ok(['presence' => $presence]);
});
