<?php

declare(strict_types=1);

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
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
    if (!hash_equals(SessionManager::pendingMfaIpAddress(), $ipAddress)) {
        SessionManager::clearPendingMfa();
        throw new ApiException(401, 'mfa_login_expired', 'The multi-factor sign-in context changed. Start again.');
    }
    $pdo = Database::connect($config);
    $user = SessionManager::pendingMfaUser(new UserRepository($pdo));
    $flow = SessionManager::pendingMfaFlow();
    $remaining = (new MfaService($pdo, $config))->consumeRecoveryCode(
        $user,
        Request::string($payload, 'recovery_code'),
        'mfa_login',
        $ipAddress,
    );
    if ($flow === 'restore') {
        $user = (new AccountClosureService($pdo, $config))->completeRestore($user->id, $ipAddress);
    }
    (new AuthService($pdo, $config))->completeLogin($user, $ipAddress);
    SessionManager::login($user);
    SessionManager::establishPrivilegedStepUp($user, 'recovery_code');

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
        'mfa_method' => 'recovery_code',
        'recovery_codes_remaining' => $remaining,
        'restored' => $flow === 'restore',
    ]);
});
