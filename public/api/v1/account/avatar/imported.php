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
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

// Hands the picture fetched from Google or Twitch to the crop step, once.
try {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $import = (new AvatarService($pdo, $config))->takeImport($actor->id);
    if ($import === null) {
        throw new ApiException(404, 'picture_not_found', 'No fetched picture is waiting. Please fetch it again.');
    }
    http_response_code(200);
    header('Content-Type: ' . $import['media_type']);
    header('Content-Length: ' . strlen($import['image']));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');
    echo $import['image'];
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
