<?php

declare(strict_types=1);

use ChitChat\Account\AvatarService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Upload\IncomingFile;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('attachment_upload', 'user:' . $actor->id);

    return ApiResult::ok([
        'avatar' => (new AvatarService($pdo, $config))->upload(
            $actor,
            IncomingFile::fromGlobal('avatar'),
            Request::clientIp(),
        ),
    ]);
});
