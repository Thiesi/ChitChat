<?php

declare(strict_types=1);

use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $payload = Request::json();
    $all = $payload['all'] ?? false;
    if (!is_bool($all)) {
        throw new ApiException(400, 'validation_error', 'all must be a boolean.');
    }

    $service = new PrivacyNotificationService($pdo);
    if ($all) {
        $updated = $service->markAllRead($actor);
    } else {
        $ids = $payload['ids'] ?? null;
        if (!is_array($ids)) {
            throw new ApiException(400, 'validation_error', 'ids must be an array when all is false.');
        }
        $updated = $service->markRead($actor, array_values($ids));
    }

    return ApiResult::ok([
        'updated' => $updated,
        'unread_count' => $service->unreadCount($actor),
    ]);
});
