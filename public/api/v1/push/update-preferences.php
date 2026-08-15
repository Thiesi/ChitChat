<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\WebPush\NotificationPreferenceService;

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

    $preferences = new NotificationPreferenceService($pdo);
    $preferences->setMentionedPushEnabled($actor->id, Request::boolean($payload, 'mentioned_push_enabled'));
    $preferences->setQuietHours(
        $actor->id,
        Request::optionalInteger($payload, 'quiet_hours_start'),
        Request::optionalInteger($payload, 'quiet_hours_end'),
        Request::optionalString($payload, 'quiet_hours_timezone'),
    );

    return ApiResult::ok(['preferences' => $preferences->get($actor->id)]);
});
