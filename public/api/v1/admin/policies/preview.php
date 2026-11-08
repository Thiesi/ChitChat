<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Policy\PolicyService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    PolicyService::requireAdministrator($actor);
    (new \ChitChat\Http\RateLimiter($pdo, $config->rateLimits))->consume('policy_preview', 'user:' . $actor->id);
    return ApiResult::ok(['html' => (new PolicyService($pdo, $config))->preview($actor, Request::string($payload, 'markdown'))]);
});
