<?php

declare(strict_types=1);

use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Auth\GuestService;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// The puzzle the browser solves before "Look around as a guest" starts a session.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    if (!(new GuestService($pdo))->available()) {
        throw new ApiException(403, 'guest_access_disabled', 'Guest access is not available.');
    }
    $challenge = (new RegistrationProtectionService($pdo, $config))->guestChallenge();

    return ApiResult::ok([
        'challenge' => $challenge->issue($_SESSION, time()),
    ]);
});
