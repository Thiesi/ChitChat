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
    SessionManager::requirePrivilegedStepUp($actor, $config);
    $kind = Request::string($payload, 'document');
    $material = array_key_exists('material', $payload) ? Request::boolean($payload, 'material') : false;
    return ApiResult::ok(['document' => (new PolicyService($pdo, $config))->update($actor, $kind,
        $kind === 'legal_notice' ? 'none' : Request::string($payload, 'source'),
        Request::optionalString($payload, 'markdown'), Request::optionalString($payload, 'url'),
        $material, Request::optionalString($payload, 'change_summary'), Request::clientIp())]);
});
