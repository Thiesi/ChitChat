<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $users = new UserRepository(Database::connect($config));
    $user = SessionManager::currentUser($users);

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user?->toArray(),
        'privacy' => [
            'direct_messages' => [
                'end_to_end_encrypted' => false,
                'admin_inspection_enabled' => $config->directMessageInspectionEnabled,
                'admin_inspection_role' => $config->directMessageInspectionRole,
                'retention' => 'permanent',
            ],
        ],
    ]);
});
