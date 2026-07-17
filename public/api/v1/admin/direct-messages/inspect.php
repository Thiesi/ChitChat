<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageInspectionService;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    return ApiResult::ok((new DirectMessageInspectionService($pdo, $config))->inspect(
        actor: $actor,
        userAId: Request::integer($payload, 'user_a_id'),
        userBId: Request::integer($payload, 'user_b_id'),
        reasonInput: Request::string($payload, 'reason'),
        beforeId: Request::optionalInteger($payload, 'before_id'),
        limit: Request::optionalInteger($payload, 'limit') ?? 50,
        ipAddress: Request::clientIp(),
    ));
});
