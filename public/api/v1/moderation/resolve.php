<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Moderation\ReportService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('moderation_action', 'user:' . $actor->id);

    return ApiResult::ok([
        'case' => (new ReportService($pdo))->resolve(
            actor: $actor,
            caseId: Request::integer($payload, 'case_id'),
            status: Request::string($payload, 'status'),
            resolutionCode: Request::string($payload, 'resolution_code'),
            noteInput: Request::optionalString($payload, 'resolution_note'),
            ipAddress: Request::clientIp(),
        ),
    ]);
});
