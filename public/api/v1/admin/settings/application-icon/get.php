<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\InstallationIcon\IconService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    header('Cache-Control: no-store');
    return ApiResult::ok(['application_icon' => (new IconService($pdo, $config))->get($actor)]);
});
