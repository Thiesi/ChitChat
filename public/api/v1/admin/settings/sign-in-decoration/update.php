<?php

declare(strict_types=1);

use ChitChat\Admin\SignInDecorationService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';

// Chooses the sign-in screen's seasonal decoration by hand (#242); "off" removes it.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    if (!$actor->hasRole('super_admin')) {
        throw new ApiException(403, 'forbidden', 'The sign-in decoration requires Super-Administrator access.');
    }
    SessionManager::requirePrivilegedStepUp($actor, $config);

    return ApiResult::ok([
        'sign_in_decoration' => (new SignInDecorationService($pdo))->update(
            $actor,
            SignInDecorationService::validate($payload['sign_in_decoration'] ?? null),
            Request::clientIp(),
        ),
    ]);
});
