<?php

declare(strict_types=1);

use ChitChat\Admin\AccessibilityFeedbackService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';

// Sets where "Report an accessibility problem" leads (#219): {"enabled": bool, "url": string|null}.
// url null means the project's issue form; enabled false hides the link and keeps the saved url.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    if (!$actor->hasRole('super_admin')) {
        throw new ApiException(403, 'forbidden', 'The accessibility feedback setting requires Super-Administrator access.');
    }
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $enabled = Request::boolean($payload, 'enabled');
    if (!array_key_exists('url', $payload)) {
        throw new ApiException(400, 'validation_error', 'url is required: an https: or mailto: address, or null for the project\'s form.');
    }

    return ApiResult::ok([
        'accessibility_feedback' => (new AccessibilityFeedbackService($pdo))->update(
            $actor,
            $enabled,
            $payload['url'],
            Request::clientIp(),
        ),
    ]);
});
