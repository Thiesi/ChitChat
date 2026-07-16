<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Upload\AttachmentMetadataService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    $encodedIds = $_GET['message_ids'] ?? null;
    if (!is_string($encodedIds) || $encodedIds === '') {
        throw new ApiException(400, 'validation_error', 'message_ids must be a comma-separated query parameter.');
    }
    $parts = explode(',', $encodedIds);
    if (count($parts) > 100) {
        throw new ApiException(400, 'validation_error', 'message_ids must contain at most 100 IDs.');
    }
    $messageIds = [];
    foreach ($parts as $part) {
        if ($part === '' || filter_var($part, FILTER_VALIDATE_INT) === false) {
            throw new ApiException(400, 'validation_error', 'message_ids must contain integers.');
        }
        $messageIds[] = (int) $part;
    }

    $attachments = (new AttachmentMetadataService($pdo))->forMessages(
        $actor,
        Request::queryInteger('room_id'),
        $messageIds,
    );

    return ApiResult::ok(['attachments' => $attachments]);
});
