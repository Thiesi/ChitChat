<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;

final class ApplicationNameServiceTest extends DatabaseTestCase
{
    public function testServerDefaultAppliesUntilASuperAdministratorOverridesIt(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new ApplicationNameService($this->pdo, $this->config);

        self::assertSame(
            ['override' => null, 'default' => $this->config->applicationName, 'effective' => $this->config->applicationName],
            $service->get($root),
        );

        $renamed = $service->update($root, '  Harbor Chat  ', '127.0.0.1');
        self::assertSame('Harbor Chat', $renamed['override']);
        self::assertSame('Harbor Chat', $renamed['effective']);
        self::assertSame('Harbor Chat', $service->effective());
        self::assertSame('Harbor Chat', ApplicationNameService::resolve($this->config));

        $restored = $service->update($root, null, '127.0.0.1');
        self::assertNull($restored['override']);
        self::assertSame($this->config->applicationName, $restored['effective']);
    }

    public function testRenamingIsAuditedWithoutNotifyingEveryAccount(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $auth->register('Member', 'another secure password', '127.0.0.2');

        (new ApplicationNameService($this->pdo, $this->config))->update($root, 'Harbor Chat', '127.0.0.1');

        self::assertSame(
            'system.application_name_updated',
            $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'system_policy_changed'")->fetchColumn(),
        );
    }

    public function testInvalidNamesAndNonAdministratorsAreRefused(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $service = new ApplicationNameService($this->pdo, $this->config);

        foreach ([
            ['validation_error', static fn () => $service->update($root, '   ', '127.0.0.1')],
            ['forbidden', static fn () => $service->get($member)],
            ['forbidden', static fn () => $service->update($member, 'Takeover', '127.0.0.2')],
        ] as [$code, $call]) {
            try {
                $call();
                self::fail('Expected ' . $code . '.');
            } catch (ApiException $exception) {
                self::assertSame($code, $exception->errorCode);
            }
        }
        self::assertSame($this->config->applicationName, $service->effective());
    }
}
