<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\AccessibilityFeedbackService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;

final class AccessibilityFeedbackServiceTest extends DatabaseTestCase
{
    public function testTheDefaultIsTheProjectFormAndChangesAreAudited(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new AccessibilityFeedbackService($this->pdo);
        $default = AccessibilityFeedbackService::DEFAULT_URL;
        self::assertSame(
            ['enabled' => true, 'url' => null, 'default_url' => $default, 'effective_url' => $default],
            $service->get($root),
        );

        $own = 'mailto:access@example.org?subject=Accessibility';
        self::assertSame(
            ['enabled' => true, 'url' => $own, 'default_url' => $default, 'effective_url' => $own],
            $service->update($root, true, $own, '127.0.0.1'),
        );
        self::assertSame($own, $service->get($root)['effective_url']);
        $audit = $this->pdo->query("SELECT actor_user_id, metadata_json::text AS metadata FROM audit_log WHERE action = 'system.accessibility_feedback_updated'")?->fetchAll() ?: [];
        self::assertCount(1, $audit);
        self::assertSame($root->id, (int) $audit[0]['actor_user_id']);
        self::assertEquals(
            ['old' => ['enabled' => true, 'url' => null], 'new' => ['enabled' => true, 'url' => $own]],
            json_decode((string) $audit[0]['metadata'], true, 4, JSON_THROW_ON_ERROR),
        );

        // The same values again: a successful no-op, not audited.
        $service->update($root, true, $own, '127.0.0.1');
        self::assertSame(1, $this->auditCount());

        // Off keeps the saved address, so switching back on restores it.
        self::assertSame(
            ['enabled' => false, 'url' => $own, 'default_url' => $default, 'effective_url' => null],
            $service->update($root, false, $own, '127.0.0.1'),
        );
        self::assertSame($own, $service->update($root, true, $own, '127.0.0.1')['effective_url']);

        // null goes back to the project's form.
        self::assertSame($default, $service->update($root, true, null, '127.0.0.1')['effective_url']);
        self::assertSame(4, $this->auditCount());
    }

    public function testOtherRolesAndInvalidAddressesAreRefusedAndTheDatabaseChecksToo(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $admin = $auth->register('Admin', 'another secure password', '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");
        $admin = (new UserRepository($this->pdo))->findAuthenticatedById($admin->id) ?? self::fail('Admin vanished.');
        $service = new AccessibilityFeedbackService($this->pdo);

        foreach ([fn () => $service->get($admin), fn () => $service->update($admin, false, null, '127.0.0.2')] as $call) {
            try {
                $call();
                self::fail('An Administrator below Super-Administrator was allowed.');
            } catch (ApiException $exception) {
                self::assertSame([403, 'forbidden'], [$exception->status, $exception->errorCode]);
            }
        }
        foreach (['javascript:alert(1)', 'data:text/html,x', 'http://example.org', 'example.org'] as $invalid) {
            try {
                $service->update($root, true, $invalid, '127.0.0.1');
                self::fail('Accepted ' . $invalid);
            } catch (ApiException $exception) {
                self::assertSame([400, 'validation_error'], [$exception->status, $exception->errorCode]);
            }
        }
        self::assertSame(AccessibilityFeedbackService::DEFAULT_URL, $service->get($root)['effective_url']);
        self::assertSame(0, $this->auditCount());

        // Written around the service, the CHECK refuses other schemes, blanks, control
        // characters and over-long values.
        foreach ([
            'javascript:alert(1)', 'data:text/html,x', 'http://example.org', 'example.org',
            'HTTPS://example.org', 'https://example.org/a b', "https://example.org/\x01",
            'https://example.org/' . str_repeat('a', 2048),
        ] as $invalid) {
            $statement = $this->pdo->prepare('UPDATE system_settings SET accessibility_feedback_url = :url WHERE id = 1');
            try {
                $statement->execute(['url' => $invalid]);
                self::fail('The CHECK constraint accepted ' . var_export($invalid, true));
            } catch (\PDOException $exception) {
                self::assertSame('23514', $exception->getCode());
                self::assertStringContainsString('system_settings_accessibility_feedback_url_check', $exception->getMessage());
            }
        }
        $this->pdo->exec("UPDATE system_settings SET accessibility_feedback_url = 'https://example.org/a11y' WHERE id = 1");
        self::assertSame('https://example.org/a11y', $service->get($root)['url']);
    }

    private function auditCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'system.accessibility_feedback_updated'")?->fetchColumn();
    }
}
