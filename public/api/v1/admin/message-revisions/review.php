<?php

declare(strict_types=1);

use ChitChat\Admin\MessageRevisionReviewService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
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
    SessionManager::requirePrivilegedStepUp($actor, $config);

    return ApiResult::ok((new MessageRevisionReviewService($pdo, $config))->review(
        actor: $actor,
        kindInput: Request::string($payload, 'kind'),
        messageId: Request::integer($payload, 'message_id'),
        reasonInput: Request::string($payload, 'reason'),
        ipAddress: Request::clientIp(),
    ));
});
