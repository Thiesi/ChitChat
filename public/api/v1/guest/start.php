<?php

declare(strict_types=1);

use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Auth\GuestService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// "Look around as a guest": starts a guest session in place of signing in.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    if (SessionManager::currentUser(new UserRepository($pdo)) !== null) {
        throw new ApiException(409, 'already_signed_in', 'You are already signed in.');
    }

    $ipAddress = Request::clientIp();
    (new RateLimiter($pdo, $config->rateLimits))->consume('guest_start', 'ip:' . $ipAddress);
    (new RegistrationProtectionService($pdo, $config))->guestChallenge()->verify(
        $_SESSION,
        null,
        $payload['challenge_nonce'] ?? null,
        $payload['challenge_solution'] ?? null,
        time(),
    );
    $guest = (new GuestService($pdo))->start($ipAddress);
    SessionManager::login($guest);

    return ApiResult::created([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $guest->toSessionArray(),
    ]);
});
