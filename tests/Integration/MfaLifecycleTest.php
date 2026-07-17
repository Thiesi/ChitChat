<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\SystemSettingsService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Http\ApiException;
use PDOException;

final class MfaLifecycleTest extends DatabaseTestCase
{
    public function testRecoveryCodeIsOneUseAndPasskeyMfaRemainsEnabledAfterDepletion(): void
    {
        $user = (new AuthService($this->pdo, $this->config))->register(
            'Root',
            'a very secure password',
            '127.0.0.1',
        );
        $this->seedPasskeyMfa($user->id, true);
        $service = new MfaService($this->pdo, $this->config);

        self::assertTrue($service->requiresMfaForLogin($user));
        self::assertSame(0, $service->consumeRecoveryCode(
            $user,
            'ABCD-EF01-2345-6789-ABCD-EF01',
            'mfa_login',
            '127.0.0.1',
        ));
        self::assertTrue($service->requiresMfaForLogin($user));

        try {
            $service->consumeRecoveryCode(
                $user,
                'ABCD-EF01-2345-6789-ABCD-EF01',
                'mfa_login',
                '127.0.0.1',
            );
            self::fail('Expected recovery-code reuse to be rejected.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_recovery_code', $exception->errorCode);
        }
    }

    public function testAdministrativePolicyBlocksUnenrolledRoleButAllowsPasskeyEnrollment(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $member = $auth->register('Member', 'another secure password', '127.0.0.2');
        $this->seedPasskeyMfa($root->id, true);
        $this->setAdministrativeMfaPolicy($root, true);

        try {
            $this->insertRole($member->id, 'admin');
            self::fail('Expected the database MFA role invariant to reject the grant.');
        } catch (PDOException $exception) {
            self::assertSame('23514', $exception->getCode());
            self::assertStringContainsString('mfa_required_for_role', $exception->getMessage());
        }

        $this->seedPasskeyMfa($member->id, false);
        $this->insertRole($member->id, 'admin');
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM user_roles WHERE user_id = {$member->id} AND role = 'admin'",
        )->fetchColumn());
    }

    public function testAccountTombstoneDestroysMfaMaterial(): void
    {
        $user = (new AuthService($this->pdo, $this->config))->register(
            'Root',
            'a very secure password',
            '127.0.0.1',
        );
        $this->seedPasskeyMfa($user->id, true);

        $statement = $this->pdo->prepare("UPDATE users SET account_state = 'closed' WHERE id = :id");
        self::assertNotFalse($statement);
        $statement->execute(['id' => $user->id]);

        self::assertSame(0, $this->countRowsForUser('webauthn_credentials', $user->id));
        self::assertSame(0, $this->countRowsForUser('mfa_recovery_codes', $user->id));
        $state = $this->pdo->query(sprintf(
            'SELECT (webauthn_user_handle IS NULL)::int, (mfa_enabled_at IS NULL)::int FROM users WHERE id = %d',
            $user->id,
        ))->fetch();
        self::assertIsArray($state);
        self::assertSame(1, (int) $state[0]);
        self::assertSame(1, (int) $state[1]);
    }

    private function seedPasskeyMfa(int $userId, bool $withRecoveryCode): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET mfa_enabled_at = NOW(),
    webauthn_user_handle = decode(:handle, 'hex')
WHERE id = :id
SQL);
        self::assertNotFalse($statement);
        $statement->execute([
            'handle' => str_repeat(dechex(($userId % 15) + 1), 64),
            'id' => $userId,
        ]);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO webauthn_credentials (
    user_id, credential_id, public_key_cose, algorithm, label
)
VALUES (:id, decode(:credential, 'hex'), decode('a0', 'hex'), -7, 'Test passkey')
SQL);
        self::assertNotFalse($statement);
        $statement->execute([
            'id' => $userId,
            'credential' => str_pad(dechex($userId), 64, '0', STR_PAD_LEFT),
        ]);
        if ($withRecoveryCode) {
            (new MfaRepository($this->pdo))->replaceRecoveryCodeHashes($userId, [
                hash('sha256', 'ABCDEF0123456789ABCDEF01'),
            ]);
        }
    }

    private function setAdministrativeMfaPolicy(\ChitChat\Auth\AuthenticatedUser $root, bool $required): void
    {
        (new SystemSettingsService($this->pdo))->update(
            actor: $root,
            registrationEnabled: true,
            mfaRequiredForAdminRoles: $required,
            roomMessageRetentionDays: 0,
            directMessageRetentionDays: 0,
            auditRetentionDays: 0,
            deletedAttachmentRetentionDays: 30,
            orphanAttachmentGraceHours: 24,
            realtimeEventRetentionHours: 168,
            loginAttemptRetentionDays: 30,
            ipAddress: '127.0.0.1',
        );
    }

    private function insertRole(int $userId, string $role): void
    {
        $statement = $this->pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role)');
        self::assertNotFalse($statement);
        $statement->execute(['user_id' => $userId, 'role' => $role]);
    }

    private function countRowsForUser(string $table, int $userId): int
    {
        $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE user_id = :id', $table));
        self::assertNotFalse($statement);
        $statement->execute(['id' => $userId]);
        return (int) $statement->fetchColumn();
    }
}
