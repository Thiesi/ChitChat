<?php

declare(strict_types=1);

use ChitChat\Admin\SystemSettingsService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $payload = Request::json();
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    SessionManager::requirePrivilegedStepUp($actor, $config);

    $settings = (new SystemSettingsService($pdo))->update(
        actor: $actor,
        registrationEnabled: Request::boolean($payload, 'registration_enabled'),
        roomMessageRetentionDays: Request::integer($payload, 'room_message_retention_days'),
        directMessageRetentionDays: Request::integer($payload, 'direct_message_retention_days'),
        auditRetentionDays: Request::integer($payload, 'audit_retention_days'),
        deletedAttachmentRetentionDays: Request::integer($payload, 'deleted_attachment_retention_days'),
        orphanAttachmentGraceHours: Request::integer($payload, 'orphan_attachment_grace_hours'),
        realtimeEventRetentionHours: Request::integer($payload, 'realtime_event_retention_hours'),
        loginAttemptRetentionDays: Request::integer($payload, 'login_attempt_retention_days'),
        ipAddress: Request::clientIp(),
    );

    return ApiResult::ok(['settings' => $settings]);
});
