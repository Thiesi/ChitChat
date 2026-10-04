<?php

declare(strict_types=1);

use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Connecting a provider adds a way into the account, so it needs step-up.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);

    return ApiResult::ok([
        'url' => (new OidcService($pdo, $config))->begin(Request::string($payload, 'provider'), 'link', $actor),
    ]);
});
