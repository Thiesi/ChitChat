<?php

declare(strict_types=1);

use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Auth\WebAuthnRequest;
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
    (new MfaService($pdo, $config))->finishAssertion(
        $user,
        'mfa_login',
        WebAuthnRequest::credential($payload),
        $ipAddress,
    );
    SessionManager::login($user);
    SessionManager::establishPrivilegedStepUp($user, 'passkey');

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
        'mfa_method' => 'passkey',
    ]);
});
