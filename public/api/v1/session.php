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
    $pdo = Database::connect($config);
    $users = new UserRepository($pdo);
    $user = SessionManager::currentUser($users);
    $statement = $pdo->query(<<<'SQL'
SELECT registration_enabled::int, direct_message_retention_days
FROM system_settings
WHERE id = 1
SQL);
    if ($statement === false) {
        throw new RuntimeException('Unable to query public system policy.');
    }
    $policy = $statement->fetch();
    if (!is_array($policy)) {
        throw new RuntimeException('Public system policy is missing.');
    }
    $dmRetentionDays = (int) $policy['direct_message_retention_days'];

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user?->toArray(),
        'registration_enabled' => (int) $policy['registration_enabled'] === 1,
        'privacy' => [
            'direct_messages' => [
                'end_to_end_encrypted' => false,
                'admin_inspection_enabled' => $config->directMessageInspectionEnabled,
                'admin_inspection_role' => $config->directMessageInspectionRole,
                'retention' => $dmRetentionDays === 0
                    ? 'permanently'
                    : sprintf('for up to %d days', $dmRetentionDays),
                'retention_days' => $dmRetentionDays,
            ],
        ],
    ]);
});
