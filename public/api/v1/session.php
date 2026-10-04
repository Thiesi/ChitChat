<?php

declare(strict_types=1);

use ChitChat\Account\DisplayPreferenceService;
use ChitChat\Account\IgnoreService;
use ChitChat\Admin\LockdownService;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Realtime\TypingService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 3) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $users = new UserRepository($pdo);
    $user = SessionManager::currentUser($users);
    $statement = $pdo->query(<<<'SQL'
SELECT registration_enabled::int, direct_message_retention_days
FROM system_settings
WHERE id = 1
SQL);
    if ($statement === false) {
        throw new RuntimeException('Unable to query public system policy.');
    }
    $policy = $statement->fetch();
    if (!is_array($policy)) {
        throw new RuntimeException('Public system policy is missing.');
    }
    $dmRetentionDays = (int) $policy['direct_message_retention_days'];

    return ApiResult::ok([
        'csrf_token' => SessionManager::csrfToken(),
        'user' => $user?->toSessionArray(),
        // Date and time display; null while signed out (the client then follows the browser).
        'preferences' => $user === null ? null : (new DisplayPreferenceService($pdo))->get($user->id),
        // People this account ignores in rooms; their messages are collapsed.
        'ignored_user_ids' => $user === null ? [] : (new IgnoreService($pdo))->ignoredBy($user->id),
        // Whether this account shows and sees "… is typing".
        'share_typing' => $user === null ? false : (new TypingService($pdo))->isShared($user->id),
        // Lockdown also closes registration, so the sign-in page hides the Register tab.
        // Sign-in providers this installation offers (Google, Twitch), for the sign-in page.
        'sign_in_providers' => (new OidcService($pdo, $config))->providers(),
        // A Google or Twitch sign-up waiting for its username, if any.
        'pending_sign_up' => $user === null ? (new OidcService($pdo, $config))->pendingSignUp() : null,
        'registration_enabled' => (int) $policy['registration_enabled'] === 1 && !(new LockdownService($pdo))->status()['enabled'],
        // Public, so the sign-in page can explain a maintenance lockdown.
        'lockdown' => (static function (array $status): array {
            return ['enabled' => $status['enabled'], 'message' => $status['enabled'] ? $status['message'] : null];
        })((new LockdownService($pdo))->status()),
        'web_push' => [
            'enabled' => $config->webPushEnabled(),
            'vapid_public_key' => $config->webPushEnabled() ? $config->webPushVapidPublicKey : null,
        ],
        'security' => [
            'privileged_step_up' => $user === null
                ? [
                    'active' => false,
                    'method' => null,
                    'verified_at' => null,
                    'expires_at' => null,
                    'max_age_seconds' => $config->privilegedStepUpMaxAgeSeconds,
                ]
                : SessionManager::privilegedStepUpStatus($user, $config),
        ],
        'privacy' => [
            'direct_messages' => [
                'end_to_end_encrypted' => false,
                'admin_inspection_enabled' => $config->directMessageInspectionEnabled,
                'admin_inspection_role' => $config->directMessageInspectionRole,
                'retention' => $dmRetentionDays === 0
                    ? 'permanently'
                    : sprintf('for up to %d days', $dmRetentionDays),
                'retention_days' => $dmRetentionDays,
            ],
            'message_revisions' => [
                'admin_review_enabled' => $config->messageRevisionReviewEnabled,
                'admin_review_role' => $config->messageRevisionReviewRole,
                'reason_required' => true,
                'audit_each_review' => true,
                'participant_notification' => false,
            ],
        ],
    ]);
});
