<?php

declare(strict_types=1);

use ChitChat\Auth\GuestService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// "Block guests from this connection": no new guests from the guest's
// connection for an hour, a day or a week, and its guests end now.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('moderation_action', 'user:' . $actor->id);

    (new GuestService($pdo))->blockConnection(
        $actor,
        Request::integer($payload, 'user_id'),
        Request::integer($payload, 'duration_seconds'),
        Request::optionalString($payload, 'reason') ?? '',
        Request::clientIp(),
    );

    return ApiResult::ok(['status' => 'blocked']);
});
