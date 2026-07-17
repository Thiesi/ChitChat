<?php

declare(strict_types=1);

use ChitChat\Admin\AdminService;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use PDOException;

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
    if (!$actor->canManageUsers()) {
        throw new ApiException(403, 'forbidden', 'User administration requires Administrator access.');
    }
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $targetUserId = Request::integer($payload, 'target_user_id');
    $protectedRole = array_intersect($roles, ['super_admin', 'admin', 'chat_admin', 'global_moderator']) !== [];
    $mfa = new MfaRepository($pdo);
    if (
        $protectedRole
        && $mfa->policyRequiresMfaForAdminRoles()
        && (!$mfa->isEnabled($targetUserId) || $mfa->credentialCount($targetUserId) < 1)
    ) {
        throw new ApiException(
            409,
            'mfa_required_for_role',
            'The target account must enable MFA with at least one passkey before receiving an administrative role.',
        );
    }

    try {
        (new AdminService($pdo))->setRoles(
            $actor,
            $targetUserId,
            $roles,
            Request::clientIp(),
        );
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'mfa_required_for_role')) {
            throw new ApiException(
                409,
                'mfa_required_for_role',
                'The target account must enable MFA with at least one passkey before receiving an administrative role.',
            );
        }
        throw $exception;
    }

    return ApiResult::ok(['status' => 'roles_updated']);
});
