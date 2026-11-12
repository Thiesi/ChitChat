<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\SignInDecorationService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;

final class SignInDecorationServiceTest extends DatabaseTestCase
{
    public function testASuperAdministratorChoosesTheDecorationAndChangesAreAudited(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $service = new SignInDecorationService($this->pdo);
        self::assertSame(['value' => 'off', 'options' => ['off', 'winter', 'spring', 'celebration']], $service->get($root));

        self::assertSame('winter', $service->update($root, 'winter', '127.0.0.1')['value']);
        self::assertSame('winter', $service->get($root)['value']);
        $audit = $this->pdo->query("SELECT actor_user_id, metadata_json::text AS metadata FROM audit_log WHERE action = 'system.sign_in_decoration_updated'")?->fetchAll() ?: [];
        self::assertCount(1, $audit);
        self::assertSame($root->id, (int) $audit[0]['actor_user_id']);
        self::assertEquals(['old' => 'off', 'new' => 'winter'], json_decode((string) $audit[0]['metadata'], true, 4, JSON_THROW_ON_ERROR));

        // Saving the same value again is a successful no-op without another audit entry.
        self::assertSame('winter', $service->update($root, 'winter', '127.0.0.1')['value']);
        $service->update($root, 'off', '127.0.0.1');
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.sign_in_decoration_updated'")?->fetchColumn());
        self::assertSame('off', $service->get($root)['value']);
    }

    public function testOtherRolesAndUnknownValuesAreRefused(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");
        $admin = (new UserRepository($this->pdo))->findAuthenticatedById($admin->id) ?? self::fail('Admin vanished.');
        $service = new SignInDecorationService($this->pdo);

        foreach ([fn () => $service->get($admin), fn () => $service->update($admin, 'winter', '127.0.0.2')] as $call) {
            try {
                $call();
                self::fail('An Administrator below Super-Administrator was allowed.');
            } catch (ApiException $exception) {
                self::assertSame([403, 'forbidden'], [$exception->status, $exception->errorCode]);
            }
        }
        try {
            $service->update($root, 'autumn', '127.0.0.1');
            self::fail('An unknown decoration was accepted.');
        } catch (ApiException $exception) {
            self::assertSame([400, 'validation_error'], [$exception->status, $exception->errorCode]);
        }
        self::assertSame('off', $service->get($root)['value']);

        // The database refuses an unknown value written around the service, too.
        try {
            $this->pdo->exec("UPDATE system_settings SET sign_in_decoration = 'autumn' WHERE id = 1");
            self::fail('The CHECK constraint accepted an unknown decoration.');
        } catch (\PDOException $exception) {
            self::assertSame('23514', $exception->getCode());
            self::assertStringContainsString('system_settings_sign_in_decoration_check', $exception->getMessage());
        }
    }
}
