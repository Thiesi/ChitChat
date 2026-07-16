<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;

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
