<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\PrivilegedStepUpService;
use ChitChat\Auth\SessionManager;
use ChitChat\Http\ApiException;

final class PrivilegedStepUpServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testCurrentPasswordCreatesAuditedSessionBoundStepUp(): void
    {
        $password = 'correct privileged password';
        $actor = (new AuthService($this->pdo, $this->config))->register(
            'Root',
            $password,
            '127.0.0.1',
        );
        $service = new PrivilegedStepUpService($this->pdo, $this->config);

        self::assertFalse(SessionManager::privilegedStepUpStatus($actor, $this->config)['active']);
        try {
            SessionManager::requirePrivilegedStepUp($actor, $this->config);
            self::fail('Expected an unauthenticated sensitive action to require step-up.');
        } catch (ApiException $exception) {
            self::assertSame('step_up_required', $exception->errorCode);
        }

        try {
            $service->verify($actor, 'wrong password', '127.0.0.1');
            self::fail('Expected the wrong current password to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('step_up_invalid_credentials', $exception->errorCode);
        }
        self::assertFalse(SessionManager::privilegedStepUpStatus($actor, $this->config)['active']);

        $status = $service->verify($actor, $password, '127.0.0.1');
        self::assertTrue($status['active']);
        self::assertSame('password', $status['method']);
        self::assertNotNull($status['verified_at']);
        self::assertNotNull($status['expires_at']);
        self::assertSame($this->config->privilegedStepUpMaxAgeSeconds, $status['max_age_seconds']);
        SessionManager::requirePrivilegedStepUp($actor, $this->config);

        $actions = $this->pdo->query(<<<'SQL'
SELECT action
FROM audit_log
WHERE action LIKE 'auth.privileged_step_up_%'
ORDER BY id
SQL)->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([
            'auth.privileged_step_up_failed',
            'auth.privileged_step_up_succeeded',
        ], $actions);
    }

    public function testStepUpExpiresAndDoesNotSurviveSessionVersionChange(): void
    {
        $actor = (new AuthService($this->pdo, $this->config))->register(
            'Root',
            'correct privileged password',
            '127.0.0.1',
        );
        SessionManager::establishPrivilegedStepUp($actor);
        self::assertTrue(SessionManager::privilegedStepUpStatus($actor, $this->config)['active']);

        $_SESSION['privileged_step_up']['verified_at'] = time() - $this->config->privilegedStepUpMaxAgeSeconds - 1;
        self::assertFalse(SessionManager::privilegedStepUpStatus($actor, $this->config)['active']);
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);

        SessionManager::establishPrivilegedStepUp($actor);
        $changed = new AuthenticatedUser(
            id: $actor->id,
            username: $actor->username,
            roles: $actor->roles,
            sessionVersion: $actor->sessionVersion + 1,
        );
        self::assertFalse(SessionManager::privilegedStepUpStatus($changed, $this->config)['active']);
    }

    public function testRepeatedAttemptsUseSharedDatabaseRateLimit(): void
    {
        $actor = (new AuthService($this->pdo, $this->config))->register(
            'Root',
            'correct privileged password',
            '127.0.0.1',
        );
        $service = new PrivilegedStepUpService($this->pdo, $this->config);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            try {
                $service->verify($actor, 'wrong password', '127.0.0.1');
            } catch (ApiException $exception) {
                self::assertSame('step_up_invalid_credentials', $exception->errorCode);
            }
        }

        try {
            $service->verify($actor, 'correct privileged password', '127.0.0.1');
            self::fail('Expected the shared rate limiter to reject the eleventh attempt.');
        } catch (ApiException $exception) {
            self::assertSame(429, $exception->status);
            self::assertSame('rate_limited', $exception->errorCode);
        }
        self::assertFalse(SessionManager::privilegedStepUpStatus($actor, $this->config)['active']);
    }
}
