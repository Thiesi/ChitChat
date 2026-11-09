<?php

declare(strict_types=1);

use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    header('Cache-Control: no-store');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUserOrGuest(new UserRepository($pdo));
    $binding = SessionManager::loginBinding();
    return ApiResult::ok(['sessions' => (new LoginSessionRepository($pdo))->list($actor, $binding['id'] ?? ''),
        'maximum_sessions' => LoginSessionRepository::MAXIMUM_SESSIONS]);
});
