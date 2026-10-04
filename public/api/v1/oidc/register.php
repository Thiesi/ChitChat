<?php

declare(strict_types=1);

use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Finishes signing up with Google or Twitch: the provider account was
// confirmed already, now its owner chooses a username. The same registration
// rules and bot protection apply as for a password sign-up.
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
    $protection = new RegistrationProtectionService($pdo, $config);
    (new RateLimiter($pdo, $protection->rateLimits()))->consume('registration', 'ip:' . $ipAddress);
    $protection->challenge()->verify(
        $_SESSION,
        $payload['website'] ?? null,
        $payload['challenge_nonce'] ?? null,
        $payload['challenge_solution'] ?? null,
        time(),
    );
    $user = (new OidcService($pdo, $config))->completeSignUp(
        Request::string($payload, 'username'),
        $birthDate,
        $ipAddress,
    );
    SessionManager::login($user);

    return ApiResult::created([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user->toSessionArray(),
    ]);
});
