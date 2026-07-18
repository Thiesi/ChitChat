<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Admin\SystemSettingsService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthService;
use ChitChat\Moderation\ModerationService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;

final class PrivacyNotificationServiceTest extends DatabaseTestCase
{
    public function testAuditedActionsCreateOnlyAffectedPrivacyNotifications(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $alice = $auth->register('Alice', 'another secure password', '127.0.0.2');
        $bob = $auth->register('Bob', 'different secure password', '127.0.0.3');

        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'privacy-room', 'Privacy Room', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($alice, $room->id, '127.0.0.2');
        $message = (new MessageService($this->pdo))->send($alice, $room->id, 'Content that must not enter a notification');
        (new MessageService($this->pdo))->delete($root, $message['id'], '127.0.0.1');

        $audit = new AuditLogger($this->pdo);
        $audit->log(
            actorUserId: $root->id,
            action: 'admin.message_revisions_reviewed',
            subjectType: 'message_revision_history',
            subjectId: 'direct:77',
            metadata: [
                'message_kind' => 'direct',
                'message_id' => 77,
                'sender_user_id' => $alice->id,
                'recipient_user_id' => $bob->id,
                'reason' => 'Sensitive administrator reason that must remain only in the audit log',
                'revision_ids' => [11, 12],
            ],
            ipAddress: '127.0.0.1',
        );
        (new ModerationService($this->pdo))->resetPassword(
            $root,
            $alice->id,
            'replacement secure password',
            '127.0.0.1',
        );

        $service = new PrivacyNotificationService($this->pdo);
        $aliceTimeline = $service->timeline($alice);
        self::assertSame(3, $aliceTimeline['unread_count']);
        self::assertSame(
            ['admin_password_reset', 'revision_review', 'moderator_message_deleted'],
            array_column($aliceTimeline['notifications'], 'kind'),
        );
        self::assertStringContainsString('Privacy Room', $aliceTimeline['notifications'][2]['message']);

        $bobTimeline = $service->timeline($bob);
        self::assertSame(1, $bobTimeline['unread_count']);
        self::assertSame('revision_review', $bobTimeline['notifications'][0]['kind']);

        $rawContext = (string) $this->pdo->query(
            "SELECT string_agg(context_json::text, ' ') FROM account_notifications",
        )->fetchColumn();
        self::assertStringNotContainsString('Content that must not enter a notification', $rawContext);
        self::assertStringNotContainsString('Sensitive administrator reason', $rawContext);
        self::assertStringNotContainsString('127.0.0.1', $rawContext);
    }

    public function testPolicyChangesNotifyEveryActiveAccountButNoOpUpdatesDoNot(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $alice = $auth->register('Alice', 'another secure password', '127.0.0.2');
        $settings = new SystemSettingsService($this->pdo);

        $settings->update(
            actor: $root,
            registrationEnabled: false,
            mfaRequiredForAdminRoles: false,
            roomMessageRetentionDays: 30,
            directMessageRetentionDays: 90,
            auditRetentionDays: 365,
            deletedAttachmentRetentionDays: 30,
            orphanAttachmentGraceHours: 24,
            realtimeEventRetentionHours: 168,
            loginAttemptRetentionDays: 30,
            ipAddress: '127.0.0.1',
        );
        $settings->update(
            actor: $root,
            registrationEnabled: false,
            mfaRequiredForAdminRoles: false,
            roomMessageRetentionDays: 30,
            directMessageRetentionDays: 90,
            auditRetentionDays: 365,
            deletedAttachmentRetentionDays: 30,
            orphanAttachmentGraceHours: 24,
            realtimeEventRetentionHours: 168,
            loginAttemptRetentionDays: 30,
            ipAddress: '127.0.0.1',
        );

        $service = new PrivacyNotificationService($this->pdo);
        foreach ([$root, $alice] as $account) {
            $timeline = $service->timeline($account);
            self::assertSame(1, $timeline['unread_count']);
            self::assertSame('system_policy_changed', $timeline['notifications'][0]['kind']);
            self::assertContains('Account registration: enabled → disabled', $timeline['notifications'][0]['details']);
            self::assertContains('Room-message retention (days): 0 → 30', $timeline['notifications'][0]['details']);
        }
    }

    public function testReadStateIsAccountScopedAndTombstoningClearsNotifications(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $alice = $auth->register('Alice', 'another secure password', '127.0.0.2');
        $bob = $auth->register('Bob', 'different secure password', '127.0.0.3');
        $audit = new AuditLogger($this->pdo);

        foreach ([1, 2, 3] as $reference) {
            $audit->log(
                actorUserId: $root->id,
                action: 'auth.password_reset_by_admin',
                subjectType: 'user',
                subjectId: (string) $alice->id,
                metadata: ['reference' => $reference],
                ipAddress: '127.0.0.1',
            );
        }
        $audit->log(
            actorUserId: $root->id,
            action: 'auth.password_reset_by_admin',
            subjectType: 'user',
            subjectId: (string) $bob->id,
            metadata: [],
            ipAddress: '127.0.0.1',
        );

        $service = new PrivacyNotificationService($this->pdo);
        $firstPage = $service->timeline($alice, null, 2);
        self::assertCount(2, $firstPage['notifications']);
        self::assertSame(3, $firstPage['unread_count']);
        $firstId = $firstPage['notifications'][0]['id'];
        self::assertSame(1, $service->markRead($alice, [$firstId]));
        self::assertSame(2, $service->unreadCount($alice));
        self::assertSame(0, $service->markRead($bob, [$firstId]));
        self::assertSame(1, $service->unreadCount($bob));
        self::assertSame(2, $service->markAllRead($alice));
        self::assertSame(0, $service->unreadCount($alice));

        $this->pdo->exec(sprintf(<<<'SQL'
UPDATE users
SET account_state = 'closed',
    closure_requested_at = NOW() - INTERVAL '15 days',
    closure_finalizes_at = NOW() - INTERVAL '1 day',
    closed_at = NOW()
WHERE id = %d
SQL, $alice->id));
        self::assertSame(
            0,
            (int) $this->pdo->query(sprintf(
                'SELECT COUNT(*) FROM account_notifications WHERE user_id = %d',
                $alice->id,
            ))->fetchColumn(),
        );
    }
}
