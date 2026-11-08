<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\Endpoint;
use ChitChat\Http\RateLimiter;
use ChitChat\Http\Request;
use ChitChat\Moderation\ReportService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): never {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    (new RateLimiter($pdo, $config->rateLimits))->consume('moderation_queue', 'user:' . $actor->id);
    $image = (new ReportService($pdo))->snapshotAvatar($actor, Request::queryInteger('case_id'));
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Content-Type: image/webp');
    header('Content-Length: ' . strlen($image));
    echo $image;
    exit;
});
