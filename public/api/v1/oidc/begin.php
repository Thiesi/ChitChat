<?php

declare(strict_types=1);

use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Confirming a sensitive action ('step_up') or restoring a closing account
// ('restore') through Google or Twitch. Unlike signing in, both change
// something, so they start from a CSRF-protected request, never a plain link.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $purpose = Request::string($payload, 'purpose');
    if (!in_array($purpose, ['step_up', 'restore'], true)) {
        throw new ApiException(400, 'validation_error', 'purpose must be step_up or restore.');
    }
    $pdo = Database::connect($config);
    $actor = $purpose === 'step_up' ? SessionManager::requireUser(new UserRepository($pdo)) : null;

    return ApiResult::ok([
        'url' => (new OidcService($pdo, $config))->begin(Request::string($payload, 'provider'), $purpose, $actor),
    ]);
});
