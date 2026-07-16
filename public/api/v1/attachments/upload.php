<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\IncomingFile;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    $roomIdValue = $_POST['room_id'] ?? null;
    if (!is_string($roomIdValue) || filter_var($roomIdValue, FILTER_VALIDATE_INT) === false) {
        throw new ApiException(400, 'validation_error', 'room_id must be an integer form field.');
    }
    $caption = $_POST['caption'] ?? '';
    if (!is_string($caption)) {
        throw new ApiException(400, 'validation_error', 'caption must be a string form field.');
    }

    $message = (new AttachmentService($pdo, $config))->upload(
        actor: $actor,
        roomId: (int) $roomIdValue,
        file: IncomingFile::fromGlobal('file'),
        captionInput: $caption,
        ipAddress: Request::clientIp(),
    );

    return ApiResult::created(['message' => $message]);
});
