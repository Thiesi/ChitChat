<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\JsonResponse;
use ChitChat\Http\Request;
use ChitChat\Upload\AttachmentService;
use JsonException;
use Throwable;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

try {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $attachment = (new AttachmentService($pdo, $config))->authorizeDownload(
        $actor,
        Request::queryInteger('id'),
    );

    $inlineRequested = ($_GET['inline'] ?? null) === '1';
    $disposition = $inlineRequested && $attachment['previewable'] ? 'inline' : 'attachment';
    $fallbackName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment['name']);
    if (!is_string($fallbackName) || $fallbackName === '') {
        $fallbackName = 'attachment';
    }
    $fallbackName = substr($fallbackName, 0, 120);
    $encodedName = rawurlencode($attachment['name']);

    $stream = fopen($attachment['path'], 'rb');
    if ($stream === false) {
        throw new ApiException(410, 'attachment_storage_missing', 'The attachment file is unavailable.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    http_response_code(200);
    header('Content-Type: ' . $attachment['mime_type']);
    header('Content-Length: ' . $attachment['size_bytes']);
    header(
        'Content-Disposition: ' . $disposition
        . '; filename="' . addcslashes($fallbackName, '"\\') . '"'
        . "; filename*=UTF-8''" . $encodedName,
    );
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cross-Origin-Resource-Policy: same-origin');

    fpassthru($stream);
    fclose($stream);
    exit;
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
