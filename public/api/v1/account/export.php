<?php

declare(strict_types=1);

use ChitChat\Account\PersonalDataExportService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);

    (new RateLimiter($pdo))->consume(
        scope: 'personal_data_export',
        identifier: (string) $actor->id,
        maximumAttempts: 5,
        windowSeconds: 3600,
    );

    $export = (new PersonalDataExportService($pdo, $config))->export(
        $actor,
        Request::clientIp(),
    );
    $safeUsername = preg_replace('/[^A-Za-z0-9._-]/', '_', $actor->username) ?? 'account';

    return ApiResult::ok([
        'filename' => sprintf(
            'chitchat-personal-data-%s-%s.json',
            $safeUsername,
            gmdate('Ymd-His'),
        ),
        'export' => $export,
    ]);
});
