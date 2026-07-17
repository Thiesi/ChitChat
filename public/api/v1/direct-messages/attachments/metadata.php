<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));

    $raw = $_GET['message_ids'] ?? null;
    if (!is_string($raw) || $raw === '') {
        throw new ApiException(400, 'validation_error', 'message_ids must be a comma-separated list.');
    }
    $parts = explode(',', $raw);
    if (count($parts) < 1 || count($parts) > 100) {
        throw new ApiException(400, 'validation_error', 'message_ids must contain 1-100 message IDs.');
    }
    $messageIds = [];
    foreach ($parts as $part) {
        if (preg_match('/\A[1-9][0-9]*\z/D', $part) !== 1) {
            throw new ApiException(400, 'validation_error', 'message_ids must contain positive integers.');
        }
        $messageIds[] = (int) $part;
    }
    $messageIds = array_values(array_unique($messageIds));

    return ApiResult::ok([
        'attachments' => (new DirectMessageAttachmentService($pdo, $config))->metadata($actor, $messageIds),
    ]);
});
