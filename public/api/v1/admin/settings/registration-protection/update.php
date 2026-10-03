<?php

declare(strict_types=1);

use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    if (!$actor->hasRole('super_admin')) {
        throw new ApiException(403, 'forbidden', 'Registration protection requires Super-Administrator access.');
    }
    SessionManager::requirePrivilegedStepUp($actor, $config);

    $overrides = [];
    foreach (['rate_limit_max_attempts', 'rate_limit_window_seconds', 'min_fill_seconds', 'proof_of_work_bits'] as $name) {
        $value = $payload[$name] ?? null;
        if ($value !== null && !is_int($value)) {
            throw new ApiException(400, 'validation_error', sprintf('%s must be an integer or null.', $name));
        }
        $overrides[$name] = $value;
    }

    return ApiResult::ok([
        'registration_protection' => (new RegistrationProtectionService($pdo, $config))->update(
            $actor,
            $overrides,
            Request::clientIp(),
        ),
    ]);
});
