<?php

declare(strict_types=1);

use ChitChat\Admin\ApplicationNameService;
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
        throw new ApiException(403, 'forbidden', 'The application name requires Super-Administrator access.');
    }
    SessionManager::requirePrivilegedStepUp($actor, $config);

    $name = $payload['application_name'] ?? null;
    if ($name !== null && !is_string($name)) {
        throw new ApiException(400, 'validation_error', 'application_name must be a string or null.');
    }

    return ApiResult::ok([
        'application_name' => (new ApplicationNameService($pdo, $config))->update($actor, $name, Request::clientIp()),
    ]);
});
