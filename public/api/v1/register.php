<?php

declare(strict_types=1);

use ChitChat\Auth\AuthService;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $birthDate = $payload['birth_date'] ?? null;
    if ($birthDate !== null && !is_string($birthDate)) {
        throw new ApiException(400, 'validation_error', 'birth_date must be a string or null.');
    }

    $pdo = Database::connect($config);
    $ipAddress = Request::clientIp();
    (new RateLimiter($pdo, $config->rateLimits))->consume('registration', 'ip:' . $ipAddress);
    $auth = new AuthService($pdo, $config);
    $user = $auth->register(
        Request::string($payload, 'username'),
        Request::string($payload, 'password'),
        $ipAddress,
        $birthDate,
    );
    SessionManager::login($user);

    return ApiResult::created([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toArray(),
    ]);
});
