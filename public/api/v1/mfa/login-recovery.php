<?php

declare(strict_types=1);

use ChitChat\Account\AccountClosureService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthContinuation;
use ChitChat\Auth\NativeAuth\NativeAuthEndpoint;
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
    $pdo = Database::connect($config);
    if (NativeAuthContinuation::pending()) {
        return ApiResult::ok(NativeAuthEndpoint::protect(static fn (): array => (new NativeAuthContinuation($pdo, $config))->finishMfa(Request::string($payload, 'recovery_code'), 'recovery_code', $ipAddress)));
    }
    SessionManager::assertPendingMfaTransport($ipAddress);
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
    SessionManager::login($user, (new \ChitChat\Auth\LoginSessionRepository($pdo))->issue($user, $_SERVER['HTTP_USER_AGENT'] ?? null));
    SessionManager::establishPrivilegedStepUp($user, 'recovery_code');

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
        'mfa_method' => 'recovery_code',
        'recovery_codes_remaining' => $remaining,
        'restored' => $flow === 'restore',
    ]);
});
