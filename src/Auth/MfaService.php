<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use ChitChat\Audit\AuditLogger;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use ChitChat\Realtime\EventRepository;
use DateTimeImmutable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class MfaService
{
    public const RECOVERY_CODE_COUNT = 10;

    private readonly MfaRepository $mfa;
    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly RateLimiter $rateLimiter;
    private readonly EventRepository $events;
    private readonly WebAuthnProtocol $webauthn;

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
        $this->mfa = new MfaRepository($pdo);
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->rateLimiter = new RateLimiter($pdo, $config->rateLimits);
        $this->events = new EventRepository($pdo);
        $this->webauthn = new WebAuthnProtocol(
            $config->webauthnRpId,
            $config->webauthnOrigin,
            $config->applicationName,
            $config->webauthnChallengeTtlSeconds * 1000,
        );
    }

    /** @return array<string, mixed> */
    public function status(AuthenticatedUser $actor): array
    {
        $credentials = $this->mfa->credentialsForUser($actor->id);
        return [
            'available' => $this->config->webauthnEnabled(),
            'enabled' => $this->mfa->isEnabled($actor->id),
            'required_by_admin_policy' => $this->mfa->policyRequiresMfaForAdminRoles()
                && $this->mfa->userHasAdministrativeRole($actor->id),
            'recovery_codes_remaining' => $this->mfa->recoveryCodesRemaining($actor->id),
            'credentials' => array_map(fn (array $credential): array => $this->publicCredential($credential), $credentials),
        ];
    }

    public function requiresMfaForLogin(AuthenticatedUser $user): bool
    {
        $enabled = $this->mfa->isEnabled($user->id);
        $required = $this->mfa->policyRequiresMfaForAdminRoles()
            && $this->mfa->userHasAdministrativeRole($user->id);
        if ($required && !$this->mfa->hasCompleteMfa($user->id)) {
            throw new ApiException(
                403,
                'mfa_enrollment_required',
                'This administrative account does not satisfy the installation MFA policy. A Super-Administrator must correct the policy or role assignment.',
            );
        }
        return $enabled || $required;
    }

    /** @return array<string, mixed> */
    public function beginRegistration(AuthenticatedUser $actor, string $ipAddress): array
    {
        $this->requireAvailable();
        $this->rateLimiter->consume('mfa_management', $actor->id . '|' . $ipAddress);
        $this->pdo->beginTransaction();
        try {
            $handle = $this->mfa->userHandleForUpdate($actor->id);
            $credentials = $this->mfa->credentialsForUser($actor->id);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        $challenge = random_bytes(32);
        SessionManager::beginWebAuthnCeremony(
            'mfa_registration',
            $challenge,
            $actor->id,
            $this->config->webauthnChallengeTtlSeconds,
        );
        return $this->webauthn->registrationOptions(
            $challenge,
            $handle,
            $actor->username,
            $this->credentialDescriptors($credentials),
        );
    }

    /** @param array<string, mixed> $credential @return array{mfa:array<string,mixed>,recovery_codes:list<string>} */
    public function finishRegistration(AuthenticatedUser $actor, array $credential, string $labelInput, string $ipAddress): array
    {
        $this->requireAvailable();
        $this->rateLimiter->consume('mfa_management', $actor->id . '|' . $ipAddress);
        $challenge = SessionManager::consumeWebAuthnChallenge('mfa_registration', $actor->id);
        $label = $this->label($labelInput);
        $verified = $this->webauthn->verifyRegistration($credential, $challenge);
        $recoveryCodes = [];

        $this->pdo->beginTransaction();
        try {
            $this->mfa->userHandleForUpdate($actor->id);
            $firstCredential = $this->mfa->credentialCount($actor->id) === 0;
            $credentialId = $this->mfa->insertCredential(
                $actor->id,
                $verified['credential_id'],
                $verified['public_key_cose'],
                $verified['algorithm'],
                $verified['sign_count'],
                $verified['transports'],
                $verified['backup_eligible'],
                $verified['backup_state'],
                $label,
            );
            if ($firstCredential) {
                $recoveryCodes = $this->replaceRecoveryCodes($actor->id);
                $this->mfa->markEnabled($actor->id);
            }
            $this->audit->log(
                $actor->id,
                'auth.passkey_added',
                'webauthn_credential',
                (string) $credentialId,
                [
                    'algorithm' => $verified['algorithm'],
                    'backup_eligible' => $verified['backup_eligible'],
                    'first_credential' => $firstCredential,
                ],
                $ipAddress,
            );
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->rollBack();
            if ($exception->getCode() === '23505') {
                throw new ApiException(409, 'passkey_already_registered', 'That passkey is already registered.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }

        return ['mfa' => $this->status($actor), 'recovery_codes' => $recoveryCodes];
    }

    /** @return array<string, mixed> */
    public function beginAssertion(AuthenticatedUser $user, string $purpose, string $ipAddress): array
    {
        $this->requireAvailable();
        $this->assertPurpose($purpose);
        $this->rateLimiter->consume('mfa_assertion', $user->id . '|' . $ipAddress);
        $credentials = $this->mfa->credentialsForUser($user->id);
        if ($credentials === []) {
            throw new ApiException(409, 'passkey_not_configured', 'No passkey is registered for this account.');
        }
        $challenge = random_bytes(32);
        SessionManager::beginWebAuthnCeremony(
            $purpose,
            $challenge,
            $user->id,
            $this->config->webauthnChallengeTtlSeconds,
        );
        return $this->webauthn->assertionOptions($challenge, $this->credentialDescriptors($credentials));
    }

    /** @param array<string, mixed> $credential @return array{credential_record_id:int} */
    public function finishAssertion(AuthenticatedUser $user, string $purpose, array $credential, string $ipAddress): array
    {
        $this->requireAvailable();
        $this->assertPurpose($purpose);
        $this->rateLimiter->consume('mfa_assertion', $user->id . '|' . $ipAddress);
        $challenge = SessionManager::consumeWebAuthnChallenge($purpose, $user->id);
        $rawId = $credential['rawId'] ?? null;
        if (!is_string($rawId)) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'rawId must be a string.');
        }
        $credentialBytes = Base64Url::decode($rawId, 'rawId');

        $this->pdo->beginTransaction();
        try {
            $stored = $this->mfa->credentialForAssertion($user->id, $credentialBytes);
            if ($stored === null) {
                throw new ApiException(403, 'unknown_passkey', 'That passkey is not registered for this account.');
            }
            $handle = $this->mfa->userHandle($user->id);
            if ($handle === null) {
                throw new RuntimeException('MFA-enabled account is missing its WebAuthn user handle.');
            }
            $verified = $this->webauthn->verifyAssertion(
                $credential,
                $challenge,
                $stored['public_key_cose'],
                $stored['sign_count'],
                $handle,
            );
            if (!hash_equals($stored['credential_id'], $verified['credential_id'])) {
                throw new ApiException(403, 'unknown_passkey', 'That passkey is not registered for this account.');
            }
            $this->mfa->updateAssertionState(
                $stored['id'],
                $verified['sign_count'],
                $verified['backup_eligible'],
                $verified['backup_state'],
            );
            $this->audit->log(
                $user->id,
                $purpose === 'mfa_login' ? 'auth.mfa_login_succeeded' : 'auth.passkey_step_up_succeeded',
                'webauthn_credential',
                (string) $stored['id'],
                ['method' => 'passkey'],
                $ipAddress,
            );
            $this->pdo->commit();
            return ['credential_record_id' => $stored['id']];
        } catch (Throwable $exception) {
            $this->rollBack();
            if ($exception instanceof ApiException) {
                $this->auditFailure($user, $purpose, 'passkey', $ipAddress, $exception->errorCode);
            }
            throw $exception;
        }
    }

    public function consumeRecoveryCode(AuthenticatedUser $user, string $codeInput, string $purpose, string $ipAddress): int
    {
        $this->assertPurpose($purpose);
        $this->rateLimiter->consume('mfa_recovery', $user->id . '|' . $ipAddress);
        $normalized = $this->normalizeRecoveryCode($codeInput);
        if ($normalized === null) {
            $this->auditFailure($user, $purpose, 'recovery_code', $ipAddress, 'invalid_format');
            throw new ApiException(403, 'invalid_recovery_code', 'The recovery code is invalid or has already been used.');
        }
        $this->pdo->beginTransaction();
        try {
            if (!$this->mfa->consumeRecoveryCode($user->id, hash('sha256', $normalized))) {
                throw new ApiException(403, 'invalid_recovery_code', 'The recovery code is invalid or has already been used.');
            }
            $remaining = $this->mfa->recoveryCodesRemaining($user->id);
            $this->audit->log(
                $user->id,
                $purpose === 'mfa_login' ? 'auth.mfa_login_succeeded' : 'auth.recovery_step_up_succeeded',
                'user',
                (string) $user->id,
                ['method' => 'recovery_code', 'remaining_codes' => $remaining],
                $ipAddress,
            );
            $this->pdo->commit();
            return $remaining;
        } catch (Throwable $exception) {
            $this->rollBack();
            if ($exception instanceof ApiException) {
                $this->auditFailure($user, $purpose, 'recovery_code', $ipAddress, $exception->errorCode);
            }
            throw $exception;
        }
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(AuthenticatedUser $actor, string $ipAddress): array
    {
        $this->rateLimiter->consume('mfa_management', $actor->id . '|' . $ipAddress);
        if (!$this->mfa->isEnabled($actor->id)) {
            throw new ApiException(409, 'mfa_not_enabled', 'Enable MFA before generating recovery codes.');
        }
        $this->pdo->beginTransaction();
        try {
            $codes = $this->replaceRecoveryCodes($actor->id);
            $this->audit->log(
                $actor->id,
                'auth.mfa_recovery_codes_regenerated',
                'user',
                (string) $actor->id,
                ['count' => count($codes)],
                $ipAddress,
            );
            $this->pdo->commit();
            return $codes;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function renameCredential(AuthenticatedUser $actor, int $credentialId, string $labelInput, string $ipAddress): void
    {
        $this->rateLimiter->consume('mfa_management', $actor->id . '|' . $ipAddress);
        if ($credentialId < 1) {
            throw new ApiException(400, 'validation_error', 'credential_id must be positive.');
        }
        $label = $this->label($labelInput);
        if (!$this->mfa->renameCredential($actor->id, $credentialId, $label)) {
            throw new ApiException(404, 'passkey_not_found', 'Passkey not found.');
        }
        $this->audit->log(
            $actor->id,
            'auth.passkey_renamed',
            'webauthn_credential',
            (string) $credentialId,
            [],
            $ipAddress,
        );
    }

    public function removeCredential(AuthenticatedUser $actor, int $credentialId, string $ipAddress): void
    {
        $this->rateLimiter->consume('mfa_management', $actor->id . '|' . $ipAddress);
        if ($credentialId < 1) {
            throw new ApiException(400, 'validation_error', 'credential_id must be positive.');
        }
        $this->pdo->beginTransaction();
        try {
            if ($this->mfa->credentialCount($actor->id) <= 1) {
                throw new ApiException(409, 'last_passkey', 'The final passkey cannot be removed. Disable MFA instead when policy permits.');
            }
            if (!$this->mfa->deleteCredential($actor->id, $credentialId)) {
                throw new ApiException(404, 'passkey_not_found', 'Passkey not found.');
            }
            $this->audit->log(
                $actor->id,
                'auth.passkey_removed',
                'webauthn_credential',
                (string) $credentialId,
                [],
                $ipAddress,
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function disable(AuthenticatedUser $actor, string $ipAddress): AuthenticatedUser
    {
        $this->rateLimiter->consume('mfa_management', $actor->id . '|' . $ipAddress);
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->query('SELECT id FROM system_settings WHERE id = 1 FOR UPDATE');
            if ($lock === false || $lock->fetchColumn() === false) {
                throw new RuntimeException('Unable to lock the administrative MFA policy.');
            }
            if ($this->mfa->policyRequiresMfaForAdminRoles() && $this->mfa->userHasAdministrativeRole($actor->id)) {
                throw new ApiException(409, 'mfa_required_by_policy', 'MFA is required for this account by administrative policy.');
            }
            $newVersion = $this->mfa->clearForUser($actor->id);
            $this->audit->log($actor->id, 'auth.mfa_disabled', 'user', (string) $actor->id, [], $ipAddress);
            $this->events->publish(
                type: 'forced_logout',
                payload: [
                    'action' => 'mfa_disabled',
                    'reason' => 'Multi-factor authentication was disabled for your account.',
                    'session_version' => $newVersion,
                ],
                targetUserId: $actor->id,
                actorUserId: $actor->id,
                expiresAt: new DateTimeImmutable('+5 minutes'),
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        $user = $this->users->findAuthenticatedById($actor->id);
        if ($user === null) {
            throw new RuntimeException('Account could not be reloaded after disabling MFA.');
        }
        return $user;
    }

    private function requireAvailable(): void
    {
        if (!$this->config->webauthnEnabled()) {
            throw new ApiException(503, 'webauthn_not_configured', 'Passkeys are not configured for this installation.');
        }
        if (!extension_loaded('openssl')) {
            throw new ApiException(503, 'webauthn_unavailable', 'The OpenSSL PHP extension is required for passkeys.');
        }
    }

    /** @param list<array{id:int,credential_id:string,label:string,transports:list<string>,algorithm:int,sign_count:int,backup_eligible:bool,backup_state:bool,created_at:string,last_used_at:?string}> $credentials
     * @return list<array{id:string,transports:list<string>}>
     */
    private function credentialDescriptors(array $credentials): array
    {
        return array_map(
            static fn (array $credential): array => [
                'id' => Base64Url::encode($credential['credential_id']),
                'transports' => $credential['transports'],
            ],
            $credentials,
        );
    }

    /** @param array{id:int,credential_id:string,label:string,transports:list<string>,algorithm:int,sign_count:int,backup_eligible:bool,backup_state:bool,created_at:string,last_used_at:?string} $credential
     * @return array{id:int,label:string,transports:list<string>,algorithm:string,backup_eligible:bool,backup_state:bool,created_at:string,last_used_at:?string}
     */
    private function publicCredential(array $credential): array
    {
        return [
            'id' => $credential['id'],
            'label' => $credential['label'],
            'transports' => $credential['transports'],
            'algorithm' => $credential['algorithm'] === WebAuthnProtocol::ES256 ? 'ES256' : 'RS256',
            'backup_eligible' => $credential['backup_eligible'],
            'backup_state' => $credential['backup_state'],
            'created_at' => $credential['created_at'],
            'last_used_at' => $credential['last_used_at'],
        ];
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(int $userId): array
    {
        $codes = [];
        $hashes = [];
        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index++) {
            $normalized = strtoupper(bin2hex(random_bytes(12)));
            $codes[] = implode('-', str_split($normalized, 4));
            $hashes[] = hash('sha256', $normalized);
        }
        $this->mfa->replaceRecoveryCodeHashes($userId, $hashes);
        return $codes;
    }

    private function normalizeRecoveryCode(string $input): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $input) ?? '');
        return strlen($normalized) === 24 ? $normalized : null;
    }

    private function label(string $input): string
    {
        $label = trim($input);
        $length = mb_strlen($label, 'UTF-8');
        if ($length < 1 || $length > 80) {
            throw new ApiException(400, 'validation_error', 'Passkey label must contain 1-80 characters.');
        }
        return $label;
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, ['mfa_login', 'mfa_step_up'], true)) {
            throw new ApiException(400, 'invalid_mfa_purpose', 'Unsupported MFA operation.');
        }
    }

    private function auditFailure(AuthenticatedUser $user, string $purpose, string $method, string $ipAddress, string $reason): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }
        $this->audit->log(
            $user->id,
            $purpose === 'mfa_login' ? 'auth.mfa_login_failed' : 'auth.mfa_step_up_failed',
            'user',
            (string) $user->id,
            ['method' => $method, 'reason' => $reason],
            $ipAddress,
        );
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
