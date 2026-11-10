<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\Totp\TotpKeyRing;
use ChitChat\Auth\Totp\TotpKeySettings;
use ChitChat\Auth\Totp\TotpKeyStore;
use ChitChat\Auth\Totp\TotpProtocol;
use ChitChat\Auth\Totp\TotpRepository;
use ChitChat\Auth\Totp\TotpService;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\TestSession;

#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class TotpServiceTest extends DatabaseTestCase
{
    private TestSession $session;
    private AuthenticatedUser $actor;
    private TotpService $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new TestSession();
        $this->session->start();
        $this->config = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson(
            json_encode(['fixture' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 'fixture')]);
        $this->actor = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        SessionManager::login($this->actor, (new LoginSessionRepository($this->pdo))->issue($this->actor));
        SessionManager::establishPrivilegedStepUp($this->actor, 'password');
        $this->totp = new TotpService($this->pdo, $this->config);
    }

    protected function tearDown(): void
    {
        $this->session->stop();
        parent::tearDown();
    }

    private function fresh(): AuthenticatedUser
    {
        return (new UserRepository($this->pdo))->findAuthenticatedById($this->actor->id)
            ?? throw new \RuntimeException('Missing fixture account.');
    }

    private function seed(string $phase): string
    {
        return (new TotpRepository($this->pdo))->credential($this->actor->id, $phase)
            ?->decrypt(new TotpKeyStore($this->pdo, $this->config))
            ?? throw new \RuntimeException('Missing fixture authenticator.');
    }

    private function code(string $phase, int $offset = 0): string
    {
        return TotpProtocol::codeAtStep($this->seed($phase), intdiv(time(), 30) + $offset);
    }

    /** @return array<string,mixed> */
    private function enroll(): array
    {
        $begin = $this->totp->begin($this->fresh(), '127.0.0.1');
        return $this->totp->confirm($this->fresh(), $begin['enrollment_id'], $this->code('pending'), '127.0.0.1');
    }

    public function testFirstFactorConsumesConfirmationAndInvalidatesAllCachedConfirmationsWithoutSigningOut(): void
    {
        $login = SessionManager::loginBinding();
        $oldGeneration = $this->actor->mfaPolicyGeneration;
        $first = $this->enroll();
        self::assertCount(10, $first['recovery_codes']);
        self::assertTrue($first['mfa']['enabled']);
        self::assertTrue($first['mfa']['totp']['enabled']);
        self::assertGreaterThan($oldGeneration, $this->fresh()->mfaPolicyGeneration);
        self::assertSame($login, SessionManager::loginBinding());
        self::assertFalse(SessionManager::privilegedStepUpStatus($this->fresh(), $this->config)['active']);
        self::assertTrue((new MfaRepository($this->pdo))->hasCompleteMfa($this->actor->id));
        self::assertTrue((new MfaService($this->pdo, $this->config))->requiresMfaForLogin($this->fresh()));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM login_sessions WHERE revoked_at IS NOT NULL")->fetchColumn());

        $seed = $this->seed('active');
        $private = json_encode([$_SESSION, $this->pdo->query('SELECT * FROM audit_log')->fetchAll()], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(base64_encode($seed), $private);
        self::assertStringNotContainsString(TotpProtocol::base32($seed), $private);
        $this->assertApi('invalid_totp', fn () => $this->totp->complete(
            $this->fresh(), $this->code('active'), 'mfa_step_up', '127.0.0.1',
            function (callable $verify): void { $this->lockAccount(); $verify(); }));
    }

    public function testReplacementKeepsTheOldFactorAndRecoveryMaterialUntilAtomicConfirmation(): void
    {
        $this->enroll();
        $oldSeed = $this->seed('active');
        $oldCodes = $this->pdo->query("SELECT encode(code_hash, 'hex') FROM mfa_recovery_codes ORDER BY id")->fetchAll();
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $begin = $this->totp->begin($this->fresh(), '127.0.0.1');
        self::assertSame($oldSeed, $this->seed('active'));
        $replacement = $this->totp->confirm($this->fresh(), $begin['enrollment_id'], $this->code('pending'), '127.0.0.1');
        self::assertSame([], $replacement['recovery_codes']);
        self::assertNotSame($oldSeed, $this->seed('active'));
        self::assertSame($oldCodes, $this->pdo->query("SELECT encode(code_hash, 'hex') FROM mfa_recovery_codes ORDER BY id")->fetchAll());
        self::assertNull((new TotpRepository($this->pdo))->credential($this->actor->id, 'pending'));
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $this->assertApi('last_mfa_method', fn () => $this->totp->remove($this->fresh(), '127.0.0.1'));
        self::assertNotNull((new TotpRepository($this->pdo))->credential($this->actor->id, 'active'));
    }

    public function testPendingEnrollmentIsBoundToItsLoginAndSupersedingOrCancellingNeverRemovesActiveMfa(): void
    {
        $this->enroll();
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $first = $this->totp->begin($this->fresh(), '127.0.0.1');
        $second = $this->totp->begin($this->fresh(), '127.0.0.1');
        self::assertNotSame($first['enrollment_id'], $second['enrollment_id']);
        $this->assertApi('totp_enrollment_expired', fn () => $this->totp->confirm($this->fresh(), $first['enrollment_id'], $this->code('pending'), '127.0.0.1'));
        $originalSession = $_SESSION;
        $_SESSION = [];
        SessionManager::login($this->fresh(), (new LoginSessionRepository($this->pdo))->issue($this->fresh()));
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $this->assertApi('totp_enrollment_expired', fn () => $this->totp->confirm($this->fresh(), $second['enrollment_id'], $this->code('pending'), '127.0.0.1'));
        $_SESSION = $originalSession;
        $this->totp->cancel($this->fresh(), $second['enrollment_id'], '127.0.0.1');
        self::assertNotNull((new TotpRepository($this->pdo))->credential($this->actor->id, 'active'));
        self::assertNull((new TotpRepository($this->pdo))->credential($this->actor->id, 'pending'));
    }

    public function testFactorAndGuardedEffectRollbackTogetherButBudgetsPersistAndProofCannotCrossPurposes(): void
    {
        $this->enroll();
        $code = $this->code('active', 1);
        $before = (new TotpRepository($this->pdo))->credential($this->actor->id, 'active')->lastAcceptedStep;
        try {
            $this->totp->complete($this->fresh(), $code, 'mfa_password_reset', '127.0.0.1',
                function (callable $verify): void {
                    $this->lockAccount();
                    $verify();
                    throw new \RuntimeException('Guarded effect rolled back.');
                });
            self::fail('Expected effect failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Guarded effect rolled back.', $error->getMessage());
        }
        self::assertSame($before, (new TotpRepository($this->pdo))->credential($this->actor->id, 'active')->lastAcceptedStep);
        self::assertSame('committed', $this->totp->complete($this->fresh(), $code, 'mfa_step_up', '127.0.0.1',
            function (callable $verify): string { $this->lockAccount(); $verify(); return 'committed'; }));
        $this->assertApi('invalid_totp', fn () => $this->totp->complete($this->fresh(), $code, 'mfa_login', '127.0.0.1',
            function (callable $verify): void { $this->lockAccount(); $verify(); }));
        self::assertSame(4, (int) $this->pdo->query("SELECT attempt_count FROM request_rate_limits WHERE scope = 'totp_verification'")->fetchColumn());
    }

    public function testSharedRateBudgetCoversMalformedAndWrongProofsAcrossPurposes(): void
    {
        $limited = $this->configWith(['rateLimits' => $this->config->rateLimits->with('totp_verification', 2, 300)]);
        $service = new TotpService($this->pdo, $limited);
        $this->assertApi('validation_error', fn () => $service->complete($this->actor, '１２３４５６', 'mfa_login', '127.0.0.1', fn (callable $verify) => $verify()));
        $this->assertApi('invalid_totp', fn () => $service->complete($this->actor, '123-456', 'mfa_step_up', '127.0.0.2', fn (callable $verify) => $verify()));
        try {
            $service->complete($this->actor, '123456', 'mfa_password_reset', '127.0.0.3', fn (callable $verify) => $verify());
            self::fail('Shared account budget was bypassed.');
        } catch (ApiException $error) {
            self::assertSame(429, $error->status);
            self::assertNotNull($error->retryAfterSeconds);
            self::assertGreaterThan(0, $error->retryAfterSeconds);
        }
    }

    public function testDatabaseKeyCannotBeDisabledWhilePendingAndServerRingAlwaysWinsWrites(): void
    {
        $databaseConfig = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson('{}', '')]);
        $settings = new TotpKeySettings($this->pdo, $databaseConfig);
        self::assertSame('unavailable', $settings->status($this->actor)['mode']);
        self::assertTrue($settings->update($this->actor, true, '127.0.0.1')['available']);
        $dbService = new TotpService($this->pdo, $databaseConfig);
        $begin = $dbService->begin($this->actor, '127.0.0.1');
        $this->assertApi('totp_key_in_use', fn () => $settings->update($this->actor, false, '127.0.0.1'));
        self::assertStringStartsWith('db:', (new TotpRepository($this->pdo))->credential($this->actor->id, 'pending')->keyId);
        $this->pdo->beginTransaction();
        $key = (new TotpKeyStore($this->pdo, $this->config))->writeKey();
        $this->pdo->rollBack();
        self::assertSame('fixture', $key['id']);
        $this->assertApi('totp_server_keys_configured', fn () => (new TotpKeySettings($this->pdo, $this->config))->update($this->actor, false, '127.0.0.1'));
        $dbService->cancel($this->actor, $begin['enrollment_id'], '127.0.0.1');
        self::assertSame('unavailable', $settings->update($this->actor, false, '127.0.0.1')['mode']);
    }

    public function testRotationIsDryRunFirstBoundedAndKeepsSeedReplayCounterAndPolicyGeneration(): void
    {
        $this->enroll();
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $this->totp->begin($this->fresh(), '127.0.0.1');
        $seed = $this->seed('active');
        $pendingSeed = $this->seed('pending');
        $before = $this->pdo->query("SELECT id, phase, encode(iv, 'hex') AS iv, last_accepted_step, created_at, activated_at, last_used_at FROM totp_credentials ORDER BY phase")->fetchAll();
        $generation = $this->fresh()->mfaPolicyGeneration;
        $audit = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $ring = TotpKeyRing::fromJson(json_encode([
            'fixture' => base64_encode($this->config->totpKeyRing->key('fixture')),
            'next' => base64_encode(random_bytes(32)),
        ], JSON_THROW_ON_ERROR), 'next');
        $config = $this->configWith(['totpKeyRing' => $ring]);
        $rotation = new \ChitChat\Auth\Totp\TotpKeyRotation($this->pdo, $config);
        self::assertSame([['key_id' => 'fixture', 'active' => 1, 'pending' => 1]], $rotation->references());
        self::assertSame(['dry_run' => true, 'accounts' => 1, 'credentials' => 2, 'rewrapped' => 0,
            'next_after_user_id' => $this->actor->id, 'more' => false], $rotation->batch(1));
        self::assertSame($audit, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        self::assertSame($before, $this->pdo->query("SELECT id, phase, encode(iv, 'hex') AS iv, last_accepted_step, created_at, activated_at, last_used_at FROM totp_credentials ORDER BY phase")->fetchAll());
        self::assertSame(2, $rotation->batch(1, 0, true)['rewrapped']);
        self::assertSame($generation, $this->fresh()->mfaPolicyGeneration);
        self::assertSame($seed, (new TotpRepository($this->pdo))->credential($this->actor->id, 'active')->decrypt(new TotpKeyStore($this->pdo, $config)));
        self::assertSame($pendingSeed, (new TotpRepository($this->pdo))->credential($this->actor->id, 'pending')->decrypt(new TotpKeyStore($this->pdo, $config)));
        $after = $this->pdo->query("SELECT id, phase, encode(iv, 'hex') AS iv, last_accepted_step, created_at, activated_at, last_used_at FROM totp_credentials ORDER BY phase")->fetchAll();
        foreach ($before as $i => $row) {
            self::assertNotSame($row['iv'], $after[$i]['iv']);
            unset($row['iv'], $after[$i]['iv']);
            self::assertSame($row, $after[$i]);
        }
        self::assertSame([['key_id' => 'next', 'active' => 1, 'pending' => 1]], $rotation->references());
    }

    public function testMissingWrongAndTamperedKeysFailClosedWhileEnabledStateAndRecoveryRemain(): void
    {
        $this->enroll();
        foreach ([
            TotpKeyRing::fromJson('{}', ''),
            TotpKeyRing::fromJson(json_encode(['fixture' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 'fixture'),
        ] as $ring) {
            $config = $this->configWith(['totpKeyRing' => $ring]);
            $status = (new TotpService($this->pdo, $config))->status($this->actor->id);
            self::assertTrue($status['enabled']);
            self::assertFalse($status['available']);
            self::assertTrue((new MfaService($this->pdo, $config))->requiresMfaForLogin($this->fresh()));
            $health = (new \ChitChat\Auth\Totp\TotpKeyHealth($this->pdo, $config))->status();
            self::assertSame(1, $health['unavailable_credentials']);
            self::assertTrue($health['scan_complete']);
        }
        $this->pdo->exec("UPDATE totp_credentials SET tag = decode(repeat('00', 16), 'hex')");
        $health = (new \ChitChat\Auth\Totp\TotpKeyHealth($this->pdo, $this->config))->status();
        self::assertSame(1, $health['unavailable_credentials']);
        self::assertSame(10, (new MfaRepository($this->pdo))->recoveryCodesRemaining($this->actor->id));
    }

    public function testPasskeyRemovalWithTotpAndTombstoneUseTheSharedCompletenessRule(): void
    {
        $this->enroll();
        $statement = $this->pdo->prepare("INSERT INTO webauthn_credentials (user_id,credential_id,public_key_cose,algorithm,label) VALUES (:user,decode('1234','hex'),decode('a0','hex'),-7,'Fixture') RETURNING id");
        $statement->execute(['user' => $this->actor->id]);
        $passkey = (int) $statement->fetchColumn();
        $codes = $this->pdo->query("SELECT encode(code_hash, 'hex') FROM mfa_recovery_codes ORDER BY id")->fetchAll();
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        (new MfaService($this->pdo, $this->config))->removeCredential($this->fresh(), $passkey, '127.0.0.1');
        self::assertArrayNotHasKey('privileged_step_up', $_SESSION);
        self::assertTrue((new MfaRepository($this->pdo))->hasCompleteMfa($this->actor->id));
        self::assertSame($codes, $this->pdo->query("SELECT encode(code_hash, 'hex') FROM mfa_recovery_codes ORDER BY id")->fetchAll());
        $this->pdo->exec("UPDATE users SET account_state = 'closed', closure_requested_at = NOW() - INTERVAL '2 days',
            closure_finalizes_at = NOW() - INTERVAL '1 day', closed_at = NOW() WHERE id = " . $this->actor->id);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM totp_credentials')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mfa_recovery_codes')->fetchColumn());
    }

    public function testAddingTheFirstPasskeyToTotpPreservesRecoveryCodes(): void
    {
        $this->enroll();
        $config = $this->configWith(['webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test']);
        $mfa = new MfaService($this->pdo, $config);
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $codes = $this->pdo->query("SELECT encode(code_hash, 'hex') FROM mfa_recovery_codes ORDER BY id")->fetchAll();
        $key = \ChitChat\Tests\Support\TestPasskey::create();
        $options = $mfa->beginRegistration($this->fresh(), '127.0.0.1');
        $result = $mfa->finishRegistration($this->fresh(), $key->registration($options), 'A passkey', '127.0.0.1');
        self::assertSame([], $result['recovery_codes']);
        self::assertTrue($result['mfa']['totp']['enabled']);
        self::assertCount(1, $result['mfa']['credentials']);
        self::assertSame($codes, $this->pdo->query("SELECT encode(code_hash, 'hex') FROM mfa_recovery_codes ORDER BY id")->fetchAll());
    }

    public function testAdminPolicyAcceptsConfirmedTotpButRejectsPendingAndRecoveryOnlyAccounts(): void
    {
        $begin = $this->totp->begin($this->fresh(), '127.0.0.1');
        $update = fn () => (new \ChitChat\Admin\SystemSettingsService($this->pdo))->update(
            $this->fresh(), true, true, 0, 0, 0, 30, 24, 168, 30, '127.0.0.1');
        $this->assertApi('administrators_missing_mfa', $update);
        $this->totp->confirm($this->fresh(), $begin['enrollment_id'], $this->code('pending'), '127.0.0.1');
        self::assertTrue($update()['mfa_required_for_admin_roles']);
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'another strong secret', '127.0.0.2');
        (new MfaRepository($this->pdo))->replaceRecoveryCodeHashes($member->id, [hash('sha256', 'ABCDEF0123456789ABCDEF01')]);
        (new MfaRepository($this->pdo))->markEnabled($member->id);
        $grant = $this->pdo->prepare("INSERT INTO user_roles(user_id, role) VALUES (:id, 'admin')");
        try {
            $grant->execute(['id' => $member->id]);
            self::fail('Recovery codes alone cannot satisfy primary-factor policy.');
        } catch (\PDOException $error) {
            self::assertSame('23514', $error->getCode());
        }
        \ChitChat\Tests\Support\TestTotp::seed($this->pdo, $this->config, $member->id);
        $grant->execute(['id' => $member->id]);
        self::assertTrue((new MfaRepository($this->pdo))->hasCompleteMfa($member->id));
    }

    public function testDatabaseToServerMigrationAndExplicitRetirementRequireZeroReferences(): void
    {
        $database = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson('{}', '')]);
        (new TotpKeySettings($this->pdo, $database))->update($this->actor, true, '127.0.0.1');
        (new TotpService($this->pdo, $database))->begin($this->actor, '127.0.0.1');
        $rotation = new \ChitChat\Auth\Totp\TotpKeyRotation($this->pdo, $this->config);
        try {
            $rotation->retireDatabaseKey(true);
            self::fail('Referenced DB key was retired.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('still protects', $error->getMessage());
        }
        self::assertSame(1, $rotation->batch(1)['credentials']);
        self::assertSame(1, $rotation->batch(1, 0, true)['rewrapped']);
        self::assertTrue($rotation->retireDatabaseKey());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM totp_database_key')->fetchColumn());
        self::assertTrue($rotation->retireDatabaseKey(true));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM totp_database_key')->fetchColumn());
        self::assertSame('fixture', (new TotpRepository($this->pdo))->credential($this->actor->id, 'pending')->keyId);
    }

    public function testExpiryAndBoundedMaintenanceRemoveOnlyPendingMaterialWithoutChangingPolicy(): void
    {
        $this->enroll();
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $begin = $this->totp->begin($this->fresh(), '127.0.0.1');
        $this->pdo->exec("UPDATE totp_credentials SET created_at = clock_timestamp() - INTERVAL '10 minutes',
            expires_at = clock_timestamp() - INTERVAL '1 minute' WHERE phase = 'pending'");
        $generation = $this->fresh()->mfaPolicyGeneration;
        $active = $this->seed('active');
        $this->assertApi('totp_enrollment_expired', fn () => $this->totp->confirm($this->fresh(), $begin['enrollment_id'], $this->code('pending'), '127.0.0.1'));
        $repository = new TotpRepository($this->pdo);
        self::assertSame(1, $repository->cleanupExpired(true));
        self::assertNotNull($repository->credential($this->actor->id, 'pending'));
        self::assertSame(1, $repository->cleanupExpired(false));
        self::assertNull($repository->credential($this->actor->id, 'pending'));
        self::assertSame(0, $repository->cleanupExpired(false));
        self::assertSame($active, $this->seed('active'));
        self::assertSame($generation, $this->fresh()->mfaPolicyGeneration);
    }

    public function testRemovalRequiresACurrentlyUsableRemainingPrimaryFactor(): void
    {
        $this->enroll();
        $key = \ChitChat\Tests\Support\TestPasskey::create();
        $key->seed($this->pdo, $this->actor->id);
        SessionManager::establishPrivilegedStepUp($this->fresh(), 'totp');
        $this->assertApi('last_mfa_method', fn () => $this->totp->remove($this->fresh(), '127.0.0.1'));
        self::assertNotNull((new TotpRepository($this->pdo))->credential($this->actor->id, 'active'));
        $passkey = (int) $this->pdo->query('SELECT id FROM webauthn_credentials')->fetchColumn();
        foreach ([TotpKeyRing::fromJson('{}', ''), TotpKeyRing::fromJson(
            json_encode(['fixture' => base64_encode(random_bytes(32))], JSON_THROW_ON_ERROR), 'fixture')] as $ring) {
            $config = $this->configWith(['totpKeyRing' => $ring]);
            $this->assertApi('last_mfa_method', fn () => (new MfaService($this->pdo, $config))
                ->removeCredential($this->fresh(), $passkey, '127.0.0.1'));
            self::assertSame(1, (new MfaRepository($this->pdo))->credentialCount($this->actor->id));
        }
        $usable = $this->configWith(['webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test']);
        (new TotpService($this->pdo, $usable))->remove($this->fresh(), '127.0.0.1');
        self::assertNull((new TotpRepository($this->pdo))->credential($this->actor->id, 'active'));
    }

    public function testInvalidServerRingNeverFallsBackToDatabaseKeysButPasskeysStillWork(): void
    {
        $database = $this->configWith(['totpKeyRing' => TotpKeyRing::fromJson('{}', '')]);
        (new TotpKeySettings($this->pdo, $database))->update($this->actor, true, '127.0.0.1');
        \ChitChat\Tests\Support\TestTotp::seed($this->pdo, $database, $this->actor->id);
        $key = \ChitChat\Tests\Support\TestPasskey::create();
        $key->seed($this->pdo, $this->actor->id);
        $config = $this->configWith(['webauthnRpId' => 'chat.test', 'webauthnOrigin' => 'https://chat.test',
            'totpKeyRing' => TotpKeyRing::fromConfiguration('{', '')]);
        $store = new TotpKeyStore($this->pdo, $config);
        self::assertFalse($store->available());
        self::assertSame('unavailable', $store->mode());
        $credential = (new TotpRepository($this->pdo))->credential($this->actor->id, 'active');
        $this->assertApi('totp_unavailable', fn () => $credential->decrypt($store));
        $this->pdo->beginTransaction();
        try { $this->assertApi('totp_unavailable', fn () => $store->writeKey()); }
        finally { $this->pdo->rollBack(); }
        $health = (new \ChitChat\Auth\Totp\TotpKeyHealth($this->pdo, $config))->status();
        self::assertSame('invalid_server_key_ring', $health['error_code']);
        self::assertSame(1, $health['unavailable_credentials']);
        self::assertFalse((new TotpKeySettings($this->pdo, $config))->status($this->fresh())['database_key_switch_available']);
        $mfa = new MfaService($this->pdo, $config);
        $options = $mfa->beginAssertion($this->fresh(), 'mfa_step_up', '127.0.0.1');
        $result = $mfa->finishAssertion($this->fresh(), 'mfa_step_up', $key->assertion($options), '127.0.0.1');
        self::assertGreaterThan(0, $result['credential_record_id']);
        self::assertNotContains('totp', $mfa->status($this->fresh())['usable_methods']);
    }

    public function testLockedPendingSelectionUsesTheDatabaseExpiryClock(): void
    {
        $this->totp->begin($this->fresh(), '127.0.0.1');
        $repository = new TotpRepository($this->pdo);
        $this->pdo->beginTransaction();
        try {
            self::assertNotNull($repository->credential($this->actor->id, 'pending', true, unexpired: true));
            $this->pdo->exec("UPDATE totp_credentials SET created_at = clock_timestamp() - INTERVAL '10 minutes',
                expires_at = clock_timestamp() - INTERVAL '1 second' WHERE phase = 'pending'");
            self::assertNotNull($repository->credential($this->actor->id, 'pending', true));
            self::assertNull($repository->credential($this->actor->id, 'pending', true, unexpired: true));
        } finally { $this->pdo->rollBack(); }
    }

    private function lockAccount(): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE');
        $statement->execute(['id' => $this->actor->id]);
    }

    private function assertApi(string $code, callable $operation): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (ApiException $error) { self::assertSame($code, $error->errorCode); }
    }
}