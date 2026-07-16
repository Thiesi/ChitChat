<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ModerationService;

final class ModerationServiceTest extends DatabaseTestCase
{
    public function testKickBumpsTheTargetSessionVersion(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');

        (new ModerationService($this->pdo))->kick($admin, $target->id, 'Testing', '127.0.0.1');
        $reloaded = (new UserRepository($this->pdo))->findAuthenticatedById($target->id);

        if ($reloaded === null) {
            self::fail('Target user disappeared after kick.');
        }

        self::assertGreaterThan($target->sessionVersion, $reloaded->sessionVersion);
    }

    public function testAdministratorPasswordResetInvalidatesSessions(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');

        (new ModerationService($this->pdo))->resetPassword(
            $admin,
            $target->id,
            'replacement secure password',
            '127.0.0.1',
        );

        try {
            $auth->login('Target', 'another secure password', '127.0.0.2');
            self::fail('Expected old password rejection.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_credentials', $exception->errorCode);
        }

        self::assertSame(
            $target->id,
            $auth->login('Target', 'replacement secure password', '127.0.0.2')->id,
        );
    }

    public function testBanBlocksLoginUntilRevoked(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');
        $moderation = new ModerationService($this->pdo);

        $moderation->ban($admin, $target->id, 'Testing', null, '127.0.0.1');

        try {
            $auth->login('Target', 'another secure password', '127.0.0.2');
            self::fail('Expected banned account rejection.');
        } catch (ApiException $exception) {
            self::assertSame('account_banned', $exception->errorCode);
        }

        $moderation->unban($admin, $target->id, '127.0.0.1');
        self::assertSame(
            $target->id,
            $auth->login('Target', 'another secure password', '127.0.0.2')->id,
        );
    }
}
