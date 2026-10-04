<?php

declare(strict_types=1);

use ChitChat\Admin\LockdownService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    if (!$actor->hasRole('super_admin')) {
        throw new ApiException(403, 'forbidden', 'Maintenance lockdown requires Super-Administrator access.');
    }

    return ApiResult::ok(['lockdown' => (new LockdownService($pdo))->status()]);
});
