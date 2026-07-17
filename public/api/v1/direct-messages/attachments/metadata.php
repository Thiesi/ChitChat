<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageAttachmentAccessService;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\MessageIdList;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    $messageIds = MessageIdList::fromQuery($_GET['message_ids'] ?? null);

    return ApiResult::ok([
        'attachments' => (new DirectMessageAttachmentAccessService($pdo, $config))->metadata(
            $actor,
            $messageIds,
        ),
    ]);
});
