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
    $closure = new AccountClosureService($pdo, $config);
    $pending = $closure->authenticateRestore(
        Request::string($payload, 'username'),
        Request::string($payload, 'password'),
        $ipAddress,
    );

    if ((new MfaService($pdo, $config))->requiresMfaForLogin($pending)) {
        SessionManager::beginMfaLogin(
            $pending,
            $ipAddress,
            $config->mfaPendingLoginTtlSeconds,
            'restore',
        );
        return new ApiResult([
            'csrf_token' => SessionManager::csrfToken(),
            'restored' => false,
            'restoration_pending' => true,
            'mfa_required' => true,
            'methods' => ['passkey', 'recovery_code'],
        ], 202);
    }

    $user = $closure->completeRestore($pending->id, $ipAddress);
    (new AuthService($pdo, $config))->completeLogin($user, $ipAddress);
    SessionManager::login($user);
    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
        'restored' => true,
        'restoration_pending' => false,
        'mfa_required' => false,
    ]);
});
