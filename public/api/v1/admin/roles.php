<?php

declare(strict_types=1);

use ChitChat\Admin\AdminService;
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
    $rolesValue = $payload['roles'] ?? null;
    if (!is_array($rolesValue)) {
        throw new ApiException(400, 'validation_error', 'roles must be an array of strings.');
    }

    $roles = [];
    foreach ($rolesValue as $role) {
        if (!is_string($role)) {
            throw new ApiException(400, 'validation_error', 'roles must contain only strings.');
        }
        $roles[] = $role;
    }

    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);
    (new AdminService($pdo))->setRoles(
        $actor,
        Request::integer($payload, 'target_user_id'),
        $roles,
        Request::clientIp(),
    );

    return ApiResult::ok(['status' => 'roles_updated']);
});
