<?php

declare(strict_types=1);

use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
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
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $remaining = (new MfaService($pdo, $config))->consumeRecoveryCode(
        $actor,
        Request::string($payload, 'recovery_code'),
        'mfa_step_up',
        Request::clientIp(),
    );
    SessionManager::establishPrivilegedStepUp($actor, 'recovery_code');

    return ApiResult::ok([
        'security' => ['privileged_step_up' => SessionManager::privilegedStepUpStatus($actor, $config)],
        'recovery_codes_remaining' => $remaining,
    ]);
});
