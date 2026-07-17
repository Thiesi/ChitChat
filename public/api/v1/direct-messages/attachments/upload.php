<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\Http\ApiException;
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
    (new RateLimiter($pdo))->consume('direct_message_attachment_upload', 'user:' . $actor->id, 10, 3600);

    $recipientValue = $_POST['recipient_user_id'] ?? null;
    if (!is_string($recipientValue) || filter_var($recipientValue, FILTER_VALIDATE_INT) === false) {
        throw new ApiException(400, 'validation_error', 'recipient_user_id must be an integer form field.');
    }
    $caption = $_POST['caption'] ?? '';
    if (!is_string($caption)) {
        throw new ApiException(400, 'validation_error', 'caption must be a string form field.');
    }

    $message = (new DirectMessageAttachmentService($pdo, $config))->upload(
        actor: $actor,
        recipientUserId: (int) $recipientValue,
        file: IncomingFile::fromGlobal('file'),
        captionInput: $caption,
        ipAddress: Request::clientIp(),
    );

    return ApiResult::created(['message' => $message]);
});
