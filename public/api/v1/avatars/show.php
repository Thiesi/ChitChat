<?php

declare(strict_types=1);

use ChitChat\Account\AvatarService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\JsonResponse;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// Serves an avatar to signed-in users. Browsers revalidate every time, which
// costs a 304 at most: the ETag is the random storage key, new on every upload,
// so a changed picture shows up at once.
try {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    SessionManager::requireUserOrGuest(new UserRepository($pdo));
    $image = (new AvatarService($pdo, $config))->imagePath(Request::queryInteger('user_id'));
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');
    if ($image === null) {
        // Initials are shown instead; let the browser remember that briefly.
        header('Cache-Control: private, max-age=60');
        http_response_code(404);
        exit;
    }

    $etag = '"' . $image['key'] . '"';
    header('Cache-Control: private, no-cache');
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
        http_response_code(304);
        exit;
    }
    $size = filesize($image['path']);
    http_response_code(200);
    header('Content-Type: image/webp');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($image['path']);
    exit;
} catch (ApiException $exception) {
    JsonResponse::send([
        'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
    ], $exception->status);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    JsonResponse::send([
        'error' => ['code' => 'internal_error', 'message' => 'The request failed.'],
    ], 500);
}
