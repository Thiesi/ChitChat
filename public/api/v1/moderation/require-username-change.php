<?php

declare(strict_types=1);

use ChitChat\Account\UsernameChangeService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Replaces an offensive username with a neutral placeholder at once; the person
// must choose a new name before writing again (#164). Optionally resolves the
// person case that reported it.
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('moderation_action', 'user:' . $actor->id);

    return ApiResult::ok((new UsernameChangeService($pdo))->requireChange(
        actor: $actor,
        userId: Request::integer($payload, 'user_id'),
        reasonInput: Request::string($payload, 'reason'),
        caseId: Request::optionalInteger($payload, 'case_id'),
        ipAddress: Request::clientIp(),
    ));
});
