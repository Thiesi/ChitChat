<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\WebPush\PushSubscriptionService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume(
        'push_subscription_management',
        'user:' . $actor->id,
    );

    (new PushSubscriptionService($pdo))->subscribe(
        $actor,
        Request::string($payload, 'endpoint'),
        Request::string($payload, 'p256dh'),
        Request::string($payload, 'auth'),
        trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) !== '' ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
    );

    return ApiResult::ok(['subscribed' => true]);
});
