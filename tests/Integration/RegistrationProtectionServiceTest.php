<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\RegistrationProtectionService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;

final class RegistrationProtectionServiceTest extends DatabaseTestCase
{
    public function testServerDefaultsApplyUntilASuperAdministratorOverridesThem(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new RegistrationProtectionService($this->pdo, $this->config);
        $defaultPolicy = $this->config->rateLimitPolicy('registration');

        $initial = $service->get($root);
        self::assertSame(
            [
                'rate_limit_max_attempts' => null,
                'rate_limit_window_seconds' => null,
                'min_fill_seconds' => null,
                'proof_of_work_bits' => null,
            ],
            $initial['overrides'],
        );
        self::assertSame($defaultPolicy->maximumAttempts, $initial['effective']['rate_limit_max_attempts']);
        self::assertSame($this->config->registrationProofOfWorkBits, $initial['effective']['proof_of_work_bits']);

        $updated = $service->update($root, [
            'rate_limit_max_attempts' => 7,
            'rate_limit_window_seconds' => 600,
            'min_fill_seconds' => 0,
            'proof_of_work_bits' => 0,
        ], '127.0.0.1');

        self::assertSame(7, $updated['effective']['rate_limit_max_attempts']);
        self::assertSame(600, $updated['effective']['rate_limit_window_seconds']);
        self::assertSame(0, $updated['effective']['min_fill_seconds']);
        self::assertFalse($service->challenge()->required());
        $policy = $service->rateLimits()->get('registration');
        self::assertSame(7, $policy->maximumAttempts);
        self::assertSame(600, $policy->windowSeconds);

        $restored = $service->update($root, [
            'rate_limit_max_attempts' => null,
            'rate_limit_window_seconds' => null,
            'min_fill_seconds' => null,
            'proof_of_work_bits' => 12,
        ], '127.0.0.1');
        self::assertSame($defaultPolicy->maximumAttempts, $restored['effective']['rate_limit_max_attempts']);
        self::assertSame(12, $restored['effective']['proof_of_work_bits']);
        self::assertNull($restored['overrides']['rate_limit_max_attempts']);
    }

    public function testChangesAreAuditedWithoutNotifyingEveryAccount(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $auth->register('Member', 'another secure password', '127.0.0.2');

        (new RegistrationProtectionService($this->pdo, $this->config))->update($root, [
            'rate_limit_max_attempts' => 3,
            'rate_limit_window_seconds' => null,
            'min_fill_seconds' => 5,
            'proof_of_work_bits' => 18,
        ], '127.0.0.1');

        self::assertSame(
            'system.registration_protection_updated',
            $this->pdo->query('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')->fetchColumn(),
        );
        self::assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'system_policy_changed'")->fetchColumn(),
        );
    }

    public function testOutOfRangeValuesAreRejected(): void
    {
        $root = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new RegistrationProtectionService($this->pdo, $this->config);

        foreach ([
            ['rate_limit_max_attempts', 0],
            ['rate_limit_window_seconds', 59],
            ['min_fill_seconds', 61],
            ['proof_of_work_bits', 23],
        ] as [$name, $value]) {
            $overrides = [
                'rate_limit_max_attempts' => null,
                'rate_limit_window_seconds' => null,
                'min_fill_seconds' => null,
                'proof_of_work_bits' => null,
            ];
            $overrides[$name] = $value;
            try {
                $service->update($root, $overrides, '127.0.0.1');
                self::fail('Expected ' . $name . '=' . $value . ' to be rejected.');
            } catch (ApiException $exception) {
                self::assertSame('validation_error', $exception->errorCode);
            }
        }
    }

    public function testOnlySuperAdministratorsCanReadOrChangeTheSettings(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $service = new RegistrationProtectionService($this->pdo, $this->config);

        foreach ([
            static fn () => $service->get($member),
            static fn () => $service->update($member, [
                'rate_limit_max_attempts' => null,
                'rate_limit_window_seconds' => null,
                'min_fill_seconds' => null,
                'proof_of_work_bits' => null,
            ], '127.0.0.2'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a non-administrator to be refused.');
            } catch (ApiException $exception) {
                self::assertSame('forbidden', $exception->errorCode);
            }
        }
    }
}
