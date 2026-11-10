<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use PHPUnit\Framework\TestCase;

final class MfaPolicyGenerationTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testFactorPolicyChangesInvalidateExistingStepUpWithoutChangingLoginState(): void
    {
        $config = Config::fromEnvironment();
        $_SESSION = ['auth' => ['user_id' => 1, 'session_version' => 7]];
        $user = new AuthenticatedUser(1, 'Ada', [], 7, mfaPolicyGeneration: 2, mfaEnabled: true);
        foreach (['passkey', 'totp', 'recovery_code'] as $method) {
            SessionManager::establishPrivilegedStepUp($user, $method);
            self::assertTrue(SessionManager::privilegedStepUpStatus($user, $config)['active']);
            $changed = new AuthenticatedUser(1, 'Ada', [], 7, mfaPolicyGeneration: 3, mfaEnabled: true);
            self::assertFalse(SessionManager::privilegedStepUpStatus($changed, $config)['active']);
            self::assertSame(['user_id' => 1, 'session_version' => 7], $_SESSION['auth']);
        }
    }

    public function testPasswordAndProviderCachesCannotProvideMfaAuthorityAndGenerationIsPrivate(): void
    {
        $config = Config::fromEnvironment();
        $plain = new AuthenticatedUser(1, 'Ada', [], 7);
        $mfa = new AuthenticatedUser(1, 'Ada', [], 7, mfaEnabled: true);
        foreach (['password', 'google', 'twitch'] as $method) {
            SessionManager::establishPrivilegedStepUp($plain, $method);
            self::assertTrue(SessionManager::privilegedStepUpStatus($plain, $config)['active']);
            self::assertFalse(SessionManager::privilegedStepUpStatus($mfa, $config)['active']);
        }
        self::assertArrayNotHasKey('mfa_policy_generation', $mfa->toSessionArray());
        self::assertArrayNotHasKey('mfa_enabled', $mfa->toSessionArray());
    }
}