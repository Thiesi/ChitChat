<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\SystemSettingsService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;

final class SystemSettingsServiceTest extends DatabaseTestCase
{
    public function testSuperAdministratorCanUpdateAuditedOperationalPolicy(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $service = new SystemSettingsService($this->pdo);

        $defaults = $service->get($root);
        self::assertTrue($defaults['registration_enabled']);
        self::assertSame(0, $defaults['room_message_retention_days']);
        self::assertSame(30, $defaults['deleted_attachment_retention_days']);

        $updated = $service->update(
            actor: $root,
            registrationEnabled: false,
            roomMessageRetentionDays: 90,
            directMessageRetentionDays: 180,
            auditRetentionDays: 365,
            deletedAttachmentRetentionDays: 14,
            orphanAttachmentGraceHours: 48,
            realtimeEventRetentionHours: 72,
            loginAttemptRetentionDays: 60,
            ipAddress: '127.0.0.1',
        );

        self::assertFalse($updated['registration_enabled']);
        self::assertSame(90, $updated['room_message_retention_days']);
        self::assertSame(180, $updated['direct_message_retention_days']);
        self::assertSame(
            'system.settings_updated',
            $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
        $metadata = $this->pdo->query(
            'SELECT metadata_json::text FROM audit_log ORDER BY id DESC LIMIT 1',
        )->fetchColumn();
        self::assertIsString($metadata);
        self::assertStringContainsString('room_message_retention_days', $metadata);

        try {
            $auth->register('Blocked', 'another secure password', '127.0.0.2');
            self::fail('Expected registration to be disabled.');
        } catch (ApiException $exception) {
            self::assertSame('registration_disabled', $exception->errorCode);
        }
    }

    public function testAdministratorCannotChangeSystemPolicy(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $statement = $this->pdo->prepare("INSERT INTO user_roles (user_id, role) VALUES (:id, 'admin')");
        self::assertNotFalse($statement);
        $statement->execute(['id' => $admin->id]);
        $admin = (new UserRepository($this->pdo))->findAuthenticatedById($admin->id);
        self::assertNotNull($admin);
        self::assertTrue($root->hasRole('super_admin'));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Super-Administrator');
        (new SystemSettingsService($this->pdo))->get($admin);
    }

    public function testInvalidRetentionValueIsRejected(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register(
            'Root',
            'a very secure password',
            '127.0.0.1',
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('room_message_retention_days');
        (new SystemSettingsService($this->pdo))->update(
            $root,
            true,
            3651,
            0,
            0,
            30,
            24,
            168,
            30,
            '127.0.0.1',
        );
    }
}
