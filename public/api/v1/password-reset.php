<?php

declare(strict_types=1);

use ChitChat\Auth\PasswordResetService;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Auth\SessionManager;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $result = (new PasswordResetService(Database::connect($config), $config))->begin(
        Request::string($payload, 'username'),
        Request::string($payload, 'recovery_key'),
        Request::string($payload, 'new_password'),
        $payload['challenge_nonce'] ?? null,
        $payload['challenge_solution'] ?? null,
        Request::clientIp(),
    );
    return new ApiResult($result, $result['status'] === 'pending_mfa' ? 202 : 200);
});
