<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimitPolicySet;

final class AuthServiceTest extends DatabaseTestCase
{
    public function testFirstRegisteredUserBecomesSuperAdministrator(): void
    {
        $auth = new AuthService($this->pdo, $this->config);

        $first = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $second = $auth->register('Bob', 'another secure password', '127.0.0.2');

        self::assertTrue($first->hasRole('super_admin'));
        self::assertSame([], $second->roles);
    }

    public function testUsernameUniquenessIsCaseInsensitive(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Alice', 'a very secure password', '127.0.0.1');

        try {
            $auth->register('ALICE', 'another secure password', '127.0.0.2');
            self::fail('Expected duplicate username rejection.');
        } catch (ApiException $exception) {
            self::assertSame(409, $exception->status);
            self::assertSame('username_taken', $exception->errorCode);
        }
    }

    public function testLoginAcceptsCorrectPasswordAndRejectsIncorrectPassword(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $registered = $auth->register('Alice', 'a very secure password', '127.0.0.1');

        try {
            $auth->login('alice', 'wrong password', '127.0.0.1');
            self::fail('Expected invalid credentials.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_credentials', $exception->errorCode);
        }

        $loggedIn = $auth->login('ALICE', 'a very secure password', '127.0.0.1');
        self::assertSame($registered->id, $loggedIn->id);
    }

    public function testPasswordAcceptanceDoesNotRecordSuccessfulLoginUntilCompletion(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $registered = $auth->register('Alice', 'a very secure password', '127.0.0.1');

        $pending = $auth->authenticatePassword('Alice', 'a very secure password', '127.0.0.1');
        self::assertSame($registered->id, $pending->id);
        self::assertNull($this->pdo->query(
            "SELECT last_login_at FROM users WHERE id = {$registered->id}",
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM login_attempts WHERE successful = TRUE AND username_canonical = 'alice'",
        )->fetchColumn());

        $auth->completeLogin($pending, '127.0.0.1');
        self::assertNotFalse($this->pdo->query(
            "SELECT last_login_at FROM users WHERE id = {$registered->id}",
        )->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM login_attempts WHERE successful = TRUE AND username_canonical = 'alice'",
        )->fetchColumn());
    }

    public function testRepeatedFailuresThrottleLogin(): void
    {
        $config = $this->configWithThrottle(2);
        $auth = new AuthService($this->pdo, $config);
        $auth->register('Alice', 'a very secure password', '127.0.0.1');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $auth->login('Alice', 'wrong password', '127.0.0.1');
            } catch (ApiException $exception) {
                self::assertSame('invalid_credentials', $exception->errorCode);
            }
        }

        try {
            $auth->login('Alice', 'a very secure password', '127.0.0.1');
            self::fail('Expected throttled login.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('login_throttled', $exception->errorCode);
            self::assertGreaterThan(0, $exception->retryAfterSeconds);
            self::assertLessThanOrEqual(900, $exception->retryAfterSeconds);
        }
    }

    public function testLoginRetryDelayUsesTheLastBlockingScopeAndThresholdFailure(): void
    {
        $policies = RateLimitPolicySet::defaults()->with('login', 2, 60)->with('login_ip', 3, 120);
        $auth = new AuthService($this->pdo, $this->configWith(['rateLimits' => $policies]));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(<<<'SQL'
INSERT INTO login_attempts (username_canonical, ip_address, successful, reason, created_at)
SELECT 'alice', '127.0.0.1', FALSE, 'invalid_credentials', NOW() - age * INTERVAL '1 second'
FROM (VALUES (10), (20), (30), (40), (50)) AS failures(age)
SQL);
            try {
                $auth->authenticatePassword('Alice', 'irrelevant password', '127.0.0.1');
                self::fail('Expected throttled login.');
            } catch (ApiException $exception) {
                self::assertSame('login_throttled', $exception->errorCode);
                self::assertSame('Too many failed login attempts. Try again in up to 1 minutes.', $exception->getMessage());
                self::assertSame(90, $exception->retryAfterSeconds);
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testLoginRetryDelayRoundsUpAnIpOnlyBlock(): void
    {
        $policies = RateLimitPolicySet::defaults()->with('login', 2, 60)->with('login_ip', 1, 120);
        $auth = new AuthService($this->pdo, $this->configWith(['rateLimits' => $policies]));
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(<<<'SQL'
INSERT INTO login_attempts (username_canonical, ip_address, successful, reason, created_at)
VALUES ('another', '127.0.0.1', FALSE, 'invalid_credentials', NOW() - INTERVAL '118.2 seconds')
SQL);
            try {
                $auth->authenticatePassword('Alice', 'irrelevant password', '127.0.0.1');
                self::fail('Expected IP-throttled login.');
            } catch (ApiException $exception) {
                self::assertSame('login_throttled', $exception->errorCode);
                self::assertSame(2, $exception->retryAfterSeconds);
            }
        } finally {
            $this->pdo->rollBack();
        }
    }

    public function testChangingPasswordInvalidatesExistingSessions(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $user = $auth->register('Alice', 'a very secure password', '127.0.0.1');

        $updated = $auth->changePassword(
            $user,
            'a very secure password',
            'a different secure password',
            '127.0.0.1',
        );

        self::assertGreaterThan($user->sessionVersion, $updated->sessionVersion);

        try {
            $auth->login('Alice', 'a very secure password', '127.0.0.1');
            self::fail('Expected old password rejection.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_credentials', $exception->errorCode);
        }

        self::assertSame(
            $user->id,
            $auth->login('Alice', 'a different secure password', '127.0.0.1')->id,
        );
    }
}
