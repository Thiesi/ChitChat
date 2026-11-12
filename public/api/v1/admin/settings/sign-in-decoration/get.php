<?php

declare(strict_types=1);

use ChitChat\Admin\SignInDecorationService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';

// The sign-in screen's seasonal decoration and the choices offered (#242).
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    return ApiResult::ok([
        'sign_in_decoration' => (new SignInDecorationService($pdo))->get($actor),
    ]);
});
