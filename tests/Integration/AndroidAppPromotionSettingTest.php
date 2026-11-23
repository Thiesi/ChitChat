<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\SystemSettingsService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;

/** The ChitChat for Android setting (#417) in SystemSettingsService: super-admin only, audited, dark until the store URL is set. */
final class AndroidAppPromotionSettingTest extends DatabaseTestCase
{
    private const STORE = 'https://play.google.com/store/apps/details?id=de.networkxxiii.chitchat';

    public function testCannotBeSwitchedOnWhileTheStoreUrlIsUnknown(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new SystemSettingsService($this->pdo, $this->config);

        $settings = $service->get($root);
        self::assertFalse($settings['android_app_promotion']);
        self::assertFalse($settings['android_app_available']);

        try {
            $this->update($service, $root, true);
            self::fail('Expected android_app_unavailable.');
        } catch (ApiException $exception) {
            self::assertSame([409, 'android_app_unavailable'], [$exception->status, $exception->errorCode]);
        }
        self::assertSame(0, $this->column());
        self::assertSame(0, $this->auditCount(), 'A refused change writes no audit record.');

        // Switching it off, and leaving it out, are always allowed.
        self::assertFalse($this->update($service, $root, false)['android_app_promotion']);
        self::assertFalse($this->update($service, $root, null)['android_app_promotion']);
        self::assertSame(2, $this->auditCount());
    }

    public function testSuperAdministratorSwitchesItOnAndItIsAudited(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new SystemSettingsService($this->pdo, $this->config, self::STORE);
        self::assertTrue($service->get($root)['android_app_available']);

        $updated = $this->update($service, $root, true);
        self::assertTrue($updated['android_app_promotion']);
        self::assertSame(1, $this->column());
        $metadata = json_decode((string) $this->pdo->query(
            "SELECT metadata_json::text FROM audit_log WHERE action = 'system.settings_updated' ORDER BY id DESC LIMIT 1",
        )?->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertFalse($metadata['old']['android_app_promotion']);
        self::assertTrue($metadata['new']['android_app_promotion']);
        self::assertSame((string) $root->id, (string) $this->pdo->query(
            "SELECT actor_user_id FROM audit_log WHERE action = 'system.settings_updated' ORDER BY id DESC LIMIT 1",
        )?->fetchColumn());

        // Omitted: unchanged. Then off again.
        self::assertTrue($this->update($service, $root, null)['android_app_promotion']);
        self::assertFalse($this->update($service, $root, false)['android_app_promotion']);
        self::assertSame(0, $this->column());
    }

    public function testOnlyASuperAdministratorMayReadOrChangeIt(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");
        $admin = (new \ChitChat\Auth\UserRepository($this->pdo))->findAuthenticatedById($admin->id);
        self::assertNotNull($admin);
        $service = new SystemSettingsService($this->pdo, $this->config, self::STORE);

        foreach ([static fn () => $service->get($admin), fn () => $this->update($service, $admin, true)] as $attempt) {
            try {
                $attempt();
                self::fail('Expected forbidden.');
            } catch (ApiException $exception) {
                self::assertSame([403, 'forbidden'], [$exception->status, $exception->errorCode]);
            }
        }
        self::assertSame(0, $this->column());
        self::assertSame(0, $this->auditCount());
    }

    /** @return array<string, bool|int|string> */
    private function update(SystemSettingsService $service, AuthenticatedUser $actor, ?bool $promotion): array
    {
        return $service->update(
            actor: $actor,
            registrationEnabled: true,
            mfaRequiredForAdminRoles: false,
            roomMessageRetentionDays: 0,
            directMessageRetentionDays: 0,
            auditRetentionDays: 0,
            deletedAttachmentRetentionDays: 30,
            orphanAttachmentGraceHours: 24,
            realtimeEventRetentionHours: 168,
            loginAttemptRetentionDays: 30,
            ipAddress: '127.0.0.1',
            androidAppPromotion: $promotion,
        );
    }

    private function column(): int
    {
        return (int) $this->pdo->query('SELECT android_app_promotion::int FROM system_settings WHERE id = 1')?->fetchColumn();
    }

    private function auditCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.settings_updated'")?->fetchColumn();
    }
}
