<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\DirectMessage\DirectMessageInspectionService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use JsonException;

final class DirectMessageInspectionServiceTest extends DatabaseTestCase
{
    /** @throws JsonException */
    public function testSuperAdministratorInspectionReturnsHistoryAndWritesContentFreeAudit(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $alice = $auth->register('Alice', 'another secure password', '127.0.0.2');
        $bob = $auth->register('Bob', 'different secure password', '127.0.0.3');
        $messages = new DirectMessageService($this->pdo);
        $messages->send($alice, $bob->id, 'Highly private body');
        $messages->send($bob, $alice->id, 'Private reply');

        $result = (new DirectMessageInspectionService($this->pdo, $this->config))->inspect(
            actor: $root,
            userAId: $alice->id,
            userBId: $bob->id,
            reasonInput: 'Investigating a reported safety incident',
            beforeId: null,
            limit: 50,
            ipAddress: '127.0.0.1',
        );

        self::assertSame(['Highly private body', 'Private reply'], array_column($result['messages'], 'body'));
        $row = $this->pdo->query(<<<'SQL'
SELECT action, metadata_json::text AS metadata
FROM audit_log
WHERE action = 'admin.direct_messages_inspected'
ORDER BY id DESC
LIMIT 1
SQL)->fetch();
        self::assertIsArray($row);
        self::assertSame('admin.direct_messages_inspected', $row['action']);
        $metadata = json_decode((string) $row['metadata'], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame('Investigating a reported safety incident', $metadata['reason']);
        self::assertSame(2, $metadata['returned_count']);
        self::assertArrayNotHasKey('body', $metadata);
        self::assertStringNotContainsString('Highly private body', (string) $row['metadata']);
    }

    public function testDefaultPolicyRejectsAdministratorButConfiguredAdminPolicyAllowsIt(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $operator = $auth->register('Operator', 'another secure password', '127.0.0.2');
        $alice = $auth->register('Alice', 'different secure password', '127.0.0.3');
        $bob = $auth->register('Bob', 'further secure password', '127.0.0.4');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$operator->id}, 'admin')");
        $operator = (new UserRepository($this->pdo))->findAuthenticatedById($operator->id);
        self::assertNotNull($operator);

        try {
            (new DirectMessageInspectionService($this->pdo, $this->config))->inspect(
                $operator,
                $alice->id,
                $bob->id,
                'Operational review',
                null,
                50,
                '127.0.0.2',
            );
            self::fail('Expected default Super-Administrator-only inspection rejection.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        $configured = new DirectMessageInspectionService(
            $this->pdo,
            $this->inspectionConfig(enabled: true, role: 'admin'),
        );
        $result = $configured->inspect(
            $operator,
            $alice->id,
            $bob->id,
            'Operational review',
            null,
            50,
            '127.0.0.2',
        );
        self::assertSame([], $result['messages']);
        self::assertTrue($root->hasRole('super_admin'));
    }

    public function testDisabledInspectionAndMissingReasonAreRejectedWithoutAudit(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $alice = $auth->register('Alice', 'another secure password', '127.0.0.2');
        $bob = $auth->register('Bob', 'different secure password', '127.0.0.3');
        $before = (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.direct_messages_inspected'")->fetchColumn();

        try {
            (new DirectMessageInspectionService(
                $this->pdo,
                $this->inspectionConfig(enabled: false, role: 'super_admin'),
            ))->inspect($root, $alice->id, $bob->id, 'Valid reason', null, 50, '127.0.0.1');
            self::fail('Expected disabled inspection rejection.');
        } catch (ApiException $exception) {
            self::assertSame('dm_inspection_disabled', $exception->errorCode);
        }

        try {
            (new DirectMessageInspectionService($this->pdo, $this->config))->inspect(
                $root,
                $alice->id,
                $bob->id,
                'x',
                null,
                50,
                '127.0.0.1',
            );
            self::fail('Expected inspection reason validation failure.');
        } catch (ApiException $exception) {
            self::assertSame('inspection_reason_required', $exception->errorCode);
        }

        $after = (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'admin.direct_messages_inspected'")->fetchColumn();
        self::assertSame($before, $after);
    }

    public function testInspectionUserSearchRequiresPolicyAndSupportsSelfSelection(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $auth->register('Rose', 'another secure password', '127.0.0.2');

        self::assertSame(
            ['Root', 'Rose'],
            array_column(
                (new DirectMessageInspectionService($this->pdo, $this->config))->searchUsers($root, 'Ro'),
                'username',
            ),
        );
    }

    /** @param 'super_admin'|'admin' $role */
    private function inspectionConfig(bool $enabled, string $role): Config
    {
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
            directMessageInspectionEnabled: $enabled,
            directMessageInspectionRole: $role,
        );
    }
}
