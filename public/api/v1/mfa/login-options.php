<?php

declare(strict_types=1);

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
    $ipAddress = Request::clientIp();
    $pdo = Database::connect($config);
    if (NativeAuthContinuation::pending()) {
        return ApiResult::ok(NativeAuthEndpoint::protect(static fn (): array => (new NativeAuthContinuation($pdo, $config))->mfaOptions($ipAddress)));
    }
    SessionManager::assertPendingMfaTransport($ipAddress);
    $user = SessionManager::pendingMfaUser(new UserRepository($pdo));

    return ApiResult::ok([
        'public_key' => (new MfaService($pdo, $config))->beginAssertion($user, 'mfa_login', $ipAddress),
    ]);
});
