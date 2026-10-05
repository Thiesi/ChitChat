<?php

declare(strict_types=1);

use ChitChat\Auth\GuestService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    // A guest who leaves is gone for good; the name stays on their messages.
    if (isset($_SESSION['auth'])) {
        $pdo = Database::connect($config);
        $user = SessionManager::currentUser(new UserRepository($pdo));
        if ($user !== null && $user->guest) {
            (new GuestService($pdo))->leave($user, Request::clientIp());
        }
    }
    SessionManager::logout();

    return ApiResult::ok(['status' => 'logged_out']);
});
