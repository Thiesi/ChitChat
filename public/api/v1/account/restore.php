<?php

declare(strict_types=1);

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $ipAddress = Request::clientIp();
    $pdo = Database::connect($config);
    $user = (new AccountClosureService($pdo, $config))->restore(
        Request::string($payload, 'username'),
        Request::string($payload, 'password'),
        $ipAddress,
    );

    if ((new MfaService($pdo, $config))->requiresMfaForLogin($user)) {
        SessionManager::beginMfaLogin($user, $ipAddress, $config->mfaPendingLoginTtlSeconds);
        return new ApiResult([
            'csrf_token' => SessionManager::csrfToken(),
            'restored' => true,
            'mfa_required' => true,
            'methods' => ['passkey', 'recovery_code'],
        ], 202);
    }

    (new AuthService($pdo, $config))->completeLogin($user, $ipAddress);
    SessionManager::login($user);
    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
        'restored' => true,
        'mfa_required' => false,
    ]);
});
