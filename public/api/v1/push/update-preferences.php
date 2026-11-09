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
    // Parse every field before starting writes; malformed optional fields cannot
    // partially change a preference. Omitted new fields preserve their values.
    $preferences->update(
        $actor->id,
        Request::boolean($payload, 'mentioned_push_enabled'),
        array_key_exists('pinged_push_enabled', $payload) ? Request::boolean($payload, 'pinged_push_enabled') : null,
        array_key_exists('direct_message_push_enabled', $payload) ? Request::boolean($payload, 'direct_message_push_enabled') : null,
        Request::optionalInteger($payload, 'quiet_hours_start'),
        Request::optionalInteger($payload, 'quiet_hours_end'),
        Request::optionalString($payload, 'quiet_hours_timezone'),
    );
    return ApiResult::ok(['preferences' => $preferences->get($actor->id)]);
});
