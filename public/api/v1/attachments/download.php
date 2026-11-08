<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\JsonResponse;
use ChitChat\Http\Request;
use ChitChat\Upload\AttachmentDownloadResponse;
use ChitChat\Upload\AttachmentVariant;
use ChitChat\Upload\AttachmentService;
use JsonException;
use Throwable;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

try {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUserOrGuest(new UserRepository($pdo));
    $variant = AttachmentVariant::normalize($_GET['variant'] ?? null);
    $attachment = (new AttachmentService($pdo, $config))->authorizeDownload(
        $actor,
        Request::queryInteger('id'),
        $variant,
    );

    AttachmentDownloadResponse::send($attachment, ($_GET['inline'] ?? null) === '1', $variant === 'preview');
} catch (ApiException $exception) {
    JsonResponse::send([
        'error' => [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ],
    ], $exception->status);
} catch (JsonException $exception) {
    error_log($exception->getMessage());
    JsonResponse::send([
        'error' => [
            'code' => 'invalid_json',
            'message' => 'The response JSON is invalid.',
        ],
    ], 500);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    JsonResponse::send([
        'error' => [
            'code' => 'internal_error',
            'message' => $config->debug ? $exception->getMessage() : 'An internal server error occurred.',
        ],
    ], 500);
}
