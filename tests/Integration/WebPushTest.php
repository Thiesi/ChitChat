<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\WebPush\NotificationPreferenceService;
use ChitChat\WebPush\PushSubscriptionService;
use ChitChat\WebPush\WebPushDispatcher;
use Minishlink\WebPush\VAPID;

final class WebPushTest extends DatabaseTestCase
{
    public function testSubscribeIsIdempotentPerEndpointAndUnsubscribeRemovesIt(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $member = $auth->register('Member', 'a very secure password', '127.0.0.1');

        $subscriptions = new PushSubscriptionService($this->pdo);
        $subscriptions->subscribe($member, 'https://push.example.org/abc', 'p256dh-key', 'auth-key', 'TestAgent/1.0');
        $subscriptions->subscribe($member, 'https://push.example.org/abc', 'p256dh-key-rotated', 'auth-key-rotated', 'TestAgent/2.0');

        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn(),
        );
        $row = $this->pdo->query('SELECT p256dh_key, user_agent FROM push_subscriptions')->fetch();
        self::assertSame('p256dh-key-rotated', $row['p256dh_key']);
        self::assertSame('TestAgent/2.0', $row['user_agent']);

        $devices = $subscriptions->list($member);
        self::assertCount(1, $devices);
        self::assertSame('TestAgent/2.0', $devices[0]['user_agent']);

        $subscriptions->unsubscribe($member, 'https://push.example.org/abc');
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn(),
        );
    }

    public function testSubscribeRejectsNonHttpsEndpoint(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $member = $auth->register('Member', 'a very secure password', '127.0.0.1');

        try {
            (new PushSubscriptionService($this->pdo))->subscribe(
                $member,
                'http://push.example.org/abc',
                'p256dh-key',
                'auth-key',
                null,
            );
            self::fail('Expected a non-https endpoint to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('validation_error', $exception->errorCode);
        }
    }

    public function testRevokeIsScopedToTheOwningAccount(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $owner = $auth->register('Owner', 'a very secure password', '127.0.0.1');
        $outsider = $auth->register('Outsider', 'another secure password', '127.0.0.2');

        $subscriptions = new PushSubscriptionService($this->pdo);
        $subscriptions->subscribe($owner, 'https://push.example.org/owned', 'p256dh-key', 'auth-key', null);
        $deviceId = $subscriptions->list($owner)[0]['id'];

        $subscriptions->revoke($outsider, $deviceId);
        self::assertCount(1, $subscriptions->list($owner), 'A non-owner revoke attempt must not remove the device.');

        $subscriptions->revoke($owner, $deviceId);
        self::assertCount(0, $subscriptions->list($owner));
    }

    public function testNotificationPreferencesDefaultToOnAndCanBeMuted(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $member = $auth->register('Member', 'a very secure password', '127.0.0.1');

        $preferences = new NotificationPreferenceService($this->pdo);
        $defaults = $preferences->get($member->id);
        self::assertTrue($defaults['mentioned_push_enabled']);
        self::assertNull($defaults['quiet_hours']);

        $preferences->setMentionedPushEnabled($member->id, false);
        self::assertFalse($preferences->get($member->id)['mentioned_push_enabled']);

        $preferences->setMentionedPushEnabled($member->id, true);
        self::assertTrue($preferences->get($member->id)['mentioned_push_enabled']);
    }

    public function testQuietHoursMustBeSetOrClearedTogetherAndValidated(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $member = $auth->register('Member', 'a very secure password', '127.0.0.1');
        $preferences = new NotificationPreferenceService($this->pdo);

        try {
            $preferences->setQuietHours($member->id, 22, null, null);
            self::fail('Expected a partial quiet-hours update to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('validation_error', $exception->errorCode);
        }

        try {
            $preferences->setQuietHours($member->id, 22, 7, 'Not/AZone');
            self::fail('Expected an invalid timezone to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('validation_error', $exception->errorCode);
        }

        $preferences->setQuietHours($member->id, 22, 7, 'Europe/Berlin');
        $quietHours = $preferences->get($member->id)['quiet_hours'];
        self::assertSame(['start' => 22, 'end' => 7, 'timezone' => 'Europe/Berlin'], $quietHours);

        $preferences->setQuietHours($member->id, null, null, null);
        self::assertNull($preferences->get($member->id)['quiet_hours']);
    }

    public function testDispatchIsInertWhenWebPushIsNotConfigured(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $member = $auth->register('Member', 'a very secure password', '127.0.0.1');
        $this->insertMentionedNotification($member->id);

        $summary = (new WebPushDispatcher($this->pdo, $this->config))->dispatch();
        self::assertSame(
            ['processed' => 0, 'sent' => 0, 'skipped_muted' => 0, 'skipped_quiet_hours' => 0, 'pruned_subscriptions' => 0],
            $summary,
        );
        self::assertNull(
            $this->pdo->query('SELECT push_dispatched_at FROM account_notifications LIMIT 1')->fetchColumn() ?: null,
        );
    }

    public function testDispatchSkipsMutedAndQuietHoursButStillMarksProcessed(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $muted = $auth->register('Muted', 'another secure password', '127.0.0.2');
        $quiet = $auth->register('Quiet', 'yet another secure password', '127.0.0.3');

        (new NotificationPreferenceService($this->pdo))->setMentionedPushEnabled($muted->id, false);
        (new NotificationPreferenceService($this->pdo))->setQuietHours($quiet->id, 0, 23, 'UTC');

        $this->insertMentionedNotification($muted->id);
        $this->insertMentionedNotification($quiet->id);

        $config = $this->webPushConfig();
        $summary = (new WebPushDispatcher($this->pdo, $config))->dispatch();

        self::assertSame(2, $summary['processed']);
        self::assertSame(1, $summary['skipped_muted']);
        self::assertSame(1, $summary['skipped_quiet_hours']);
        self::assertSame(0, $summary['sent']);

        $pending = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM account_notifications WHERE push_dispatched_at IS NULL',
        )->fetchColumn();
        self::assertSame(0, $pending, 'Skipped notifications must still be marked dispatched, not retried.');
    }

    private function insertMentionedNotification(int $userId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO account_notifications (user_id, kind, context_json)
VALUES (:user_id, 'mentioned', '{}'::jsonb)
SQL);
        $statement->execute(['user_id' => $userId]);
    }

    private function webPushConfig(): Config
    {
        $keys = VAPID::createVapidKeys();

        return new Config(
            environment: $this->config->environment,
            debug: $this->config->debug,
            applicationName: $this->config->applicationName,
            applicationVersion: $this->config->applicationVersion,
            databaseHost: $this->config->databaseHost,
            databasePort: $this->config->databasePort,
            databaseName: $this->config->databaseName,
            databaseUser: $this->config->databaseUser,
            databasePassword: $this->config->databasePassword,
            databaseSslMode: $this->config->databaseSslMode,
            sessionName: $this->config->sessionName,
            sessionCookieSecure: $this->config->sessionCookieSecure,
            sessionCookieSameSite: $this->config->sessionCookieSameSite,
            loginMaxAttempts: $this->config->loginMaxAttempts,
            loginLockMinutes: $this->config->loginLockMinutes,
            presenceLeaseSeconds: $this->config->presenceLeaseSeconds,
            inactivityWarningSeconds: $this->config->inactivityWarningSeconds,
            attachmentStoragePath: $this->config->attachmentStoragePath,
            attachmentMaxBytes: $this->config->attachmentMaxBytes,
            directMessageInspectionEnabled: $this->config->directMessageInspectionEnabled,
            directMessageInspectionRole: $this->config->directMessageInspectionRole,
            messageRevisionReviewEnabled: $this->config->messageRevisionReviewEnabled,
            messageRevisionReviewRole: $this->config->messageRevisionReviewRole,
            sseConnectionLeaseSeconds: $this->config->sseConnectionLeaseSeconds,
            metricsBearerToken: $this->config->metricsBearerToken,
            maintenanceMaxAgeHours: $this->config->maintenanceMaxAgeHours,
            privilegedStepUpMaxAgeSeconds: $this->config->privilegedStepUpMaxAgeSeconds,
            webauthnRpId: $this->config->webauthnRpId,
            webauthnOrigin: $this->config->webauthnOrigin,
            webauthnChallengeTtlSeconds: $this->config->webauthnChallengeTtlSeconds,
            mfaPendingLoginTtlSeconds: $this->config->mfaPendingLoginTtlSeconds,
            webPushVapidPublicKey: $keys['publicKey'],
            webPushVapidPrivateKey: $keys['privateKey'],
            webPushVapidSubject: 'mailto:admin@example.org',
        );
    }
}
