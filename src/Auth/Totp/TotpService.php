<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Base64Url;
use ChitChat\Auth\MfaRepository;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final readonly class TotpService
{
    private TotpRepository $credentials;
    private TotpKeyStore $keys;
    private RateLimiter $limits;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->credentials = new TotpRepository($pdo);
        $this->keys = new TotpKeyStore($pdo, $config);
        $this->limits = new RateLimiter($pdo, $config->rateLimits);
    }

    /** @return array{enabled:bool,available:bool,created_at:?string,last_used_at:?string} */
    public function status(int $userId): array
    {
        $active = $this->credentials->credential($userId, 'active');
        $usable = $this->keys->available();
        if ($active !== null && $usable) {
            try {
                $active->decrypt($this->keys);
            } catch (ApiException) {
                $usable = false;
            }
        }
        return ['enabled' => $active !== null, 'available' => $usable,
            'created_at' => $active?->activatedAt === null ? null : (new DateTimeImmutable($active->activatedAt))->format(DATE_ATOM),
            'last_used_at' => $active?->lastUsedAt === null ? null : (new DateTimeImmutable($active->lastUsedAt))->format(DATE_ATOM)];
    }

    /** The controller gates enrollment on integrated presentation; this service is also exercised directly.
     * @return array{enrollment_id:string,secret:string,otpauth_uri:string,expires_at:string,algorithm:string,digits:int,period_seconds:int}
     */
    public function begin(AuthenticatedUser $actor, string $ip): array
    {
        $this->managementAttempt($actor, $ip);
        $this->pdo->beginTransaction();
        try {
            $fresh = (new \ChitChat\Auth\MfaPolicyGuard($this->pdo, $this->config))->lock($actor, true);
            $login = SessionManager::loginBinding();
            if ($login === null) throw new ApiException(401, 'authentication_required', 'Sign in again before adding an authenticator.');
            $key = $this->keys->writeKey();
            $id = Base64Url::encode(random_bytes(32));
            $seed = random_bytes(TotpProtocol::SEED_BYTES);
            $sealed = TotpCipher::encrypt($seed, $key['key'], $fresh->id, $id, $key['id']);
            $this->credentials->delete($fresh->id, 'pending');
            $this->credentials->insertPending($id, $fresh->id, $fresh->sessionVersion, $login['id'], $key['id'], $sealed);
            $pending = $this->credentials->credential($fresh->id, 'pending');
            if ($pending?->expiresAt === null) throw new RuntimeException('Authenticator enrollment was not stored.');
            $issuer = (new ApplicationNameService($this->pdo, $this->config))->effective();
            $this->pdo->commit();
            return ['enrollment_id' => $id, 'secret' => TotpProtocol::base32($seed),
                'otpauth_uri' => TotpProtocol::provisioningUri($seed, $issuer, $fresh->username),
                'expires_at' => (new DateTimeImmutable($pending->expiresAt))->format(DATE_ATOM),
                'algorithm' => 'SHA1', 'digits' => 6, 'period_seconds' => 30];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** @return array{mfa:array<string,mixed>,recovery_codes:list<string>} */
    public function confirm(AuthenticatedUser $actor, string $id, #[SensitiveParameter] string $input, string $ip): array
    {
        $this->managementAttempt($actor, $ip);
        $code = $this->verificationAttempt($actor->id, $input, $ip);
        $this->pdo->beginTransaction();
        try {
            $fresh = (new \ChitChat\Auth\MfaPolicyGuard($this->pdo, $this->config))->lock($actor, true);
            $pending = $this->pending($fresh, $id);
            $step = TotpProtocol::matchingStep($pending->decrypt($this->keys), $code, $this->clock(), -1);
            if ($step === null) throw self::invalidCode();
            $mfa = new MfaRepository($this->pdo);
            $first = $mfa->credentialCount($fresh->id) === 0
                && $this->credentials->credential($fresh->id, 'active') === null;
            $this->credentials->delete($fresh->id, 'active');
            $this->credentials->activate($pending->id, $step);
            $codes = [];
            if ($first) {
                // Match the existing once-only 10-code policy, without replacing
                // recovery material when another primary factor already exists.
                $hashes = [];
                for ($i = 0; $i < MfaService::RECOVERY_CODE_COUNT; ++$i) {
                    $raw = strtoupper(bin2hex(random_bytes(12)));
                    $codes[] = implode('-', str_split($raw, 4));
                    $hashes[] = hash('sha256', $raw);
                }
                $mfa->replaceRecoveryCodeHashes($fresh->id, $hashes);
                $mfa->markEnabled($fresh->id);
            }
            (new AuditLogger($this->pdo))->log($fresh->id, 'auth.totp_enabled', 'user', (string) $fresh->id,
                ['first_factor' => $first], $ip);
            $this->pdo->commit();
            unset($_SESSION['privileged_step_up']);
            return ['mfa' => (new MfaService($this->pdo, $this->config))->status($fresh), 'recovery_codes' => $codes];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function cancel(AuthenticatedUser $actor, string $id, string $ip): void
    {
        $this->managementAttempt($actor, $ip);
        $this->pdo->beginTransaction();
        try {
            $fresh = (new \ChitChat\Auth\MfaPolicyGuard($this->pdo, $this->config))->lock($actor, false);
            $this->pending($fresh, $id);
            $this->credentials->delete($fresh->id, 'pending');
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function remove(AuthenticatedUser $actor, string $ip): void
    {
        $this->managementAttempt($actor, $ip);
        $this->pdo->beginTransaction();
        try {
            $fresh = (new \ChitChat\Auth\MfaPolicyGuard($this->pdo, $this->config))->lock($actor, true);
            if ($this->credentials->credential($fresh->id, 'active', true) === null) {
                throw new ApiException(404, 'totp_not_found', 'No authenticator is enabled for this account.');
            }
            if (!$this->config->webauthnEnabled() || !extension_loaded('openssl')
                || (new MfaRepository($this->pdo))->credentialCount($fresh->id) === 0) {
                throw new ApiException(409, 'last_mfa_method', 'Add a working passkey before removing your authenticator app, or turn off two-step sign-in if your account allows it.');
            }
            $this->credentials->delete($fresh->id, 'active');
            (new AuditLogger($this->pdo))->log($fresh->id, 'auth.totp_removed', 'user', (string) $fresh->id, [], $ip);
            $this->pdo->commit();
            unset($_SESSION['privileged_step_up']);
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    /** Primary completion owns policy/closure/account/login/native locks before invoking verify.
     * @template T
     * @param callable(callable():void):T $complete
     * @return T
     */
    public function complete(
        AuthenticatedUser $user,
        #[SensitiveParameter] string $input,
        string $purpose,
        string $ip,
        callable $complete,
    ): mixed {
        if (!in_array($purpose, ['mfa_login', 'mfa_step_up', 'mfa_password_reset'], true)) {
            throw new RuntimeException('Unsupported authenticator ceremony.');
        }
        $code = $this->verificationAttempt($user->id, $input, $ip);
        $this->limits->consume('mfa_assertion', $user->id . '|' . $ip);
        $this->pdo->beginTransaction();
        try {
            $result = $complete(function () use ($user, $code, $purpose, $ip): void {
                $active = $this->credentials->credential($user->id, 'active', true);
                if ($active === null) throw self::invalidCode();
                $step = TotpProtocol::matchingStep($active->decrypt($this->keys), $code, $this->clock(), $active->lastAcceptedStep);
                if ($step === null) throw self::invalidCode();
                $this->credentials->accept($active->id, $step);
                (new AuditLogger($this->pdo))->log($user->id, 'auth.totp_succeeded', 'user', (string) $user->id,
                    ['method' => 'totp', 'purpose' => $purpose], $ip);
            });
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            $this->rollback();
            if ($error instanceof ApiException) {
                (new AuditLogger($this->pdo))->log($user->id, 'auth.totp_failed', 'user', (string) $user->id,
                    ['purpose' => $purpose, 'reason' => $error->errorCode], $ip);
            }
            throw $error;
        }
    }

    public static function invalidCode(): ApiException
    {
        return new ApiException(403, 'invalid_totp', 'The authenticator code is invalid or has already been used. Wait for a new code and try again.');
    }

    private function managementAttempt(AuthenticatedUser $actor, string $ip): void
    {
        $this->requireOutsideTransaction();
        if ($actor->guest) throw new ApiException(403, 'guest_not_allowed', 'Create an account to use this.');
        $this->limits->consume('mfa_management', $actor->id . '|' . $ip);
    }

    private function verificationAttempt(int $userId, #[SensitiveParameter] string $input, string $ip): string
    {
        $this->requireOutsideTransaction();
        // Shared by enrollment and every proof purpose; rollback cannot erase attempts.
        $this->limits->consume('totp_verification_ip', $ip);
        $this->limits->consume('totp_verification', (string) $userId);
        return TotpProtocol::normalizeCode($input);
    }

    private function pending(AuthenticatedUser $user, string $id): TotpCredential
    {
        $pending = $this->credentials->credential($user->id, 'pending', true, unexpired: true);
        $binding = SessionManager::loginBinding();
        if ($pending === null || !hash_equals($pending->id, $id)
            || $pending->pendingSessionVersion !== $user->sessionVersion
            || $binding === null || $pending->pendingLoginId !== $binding['id']) {
            throw new ApiException(409, 'totp_enrollment_expired', 'Start authenticator setup again.');
        }
        return $pending;
    }

    private function clock(): int
    {
        $statement = $this->pdo->query('SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()))::bigint');
        $value = $statement === false ? false : $statement->fetchColumn();
        if ($value === false) throw new RuntimeException('Authenticator clock is unavailable.');
        return (int) $value;
    }

    private function requireOutsideTransaction(): void
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Authenticator completion must own its transaction.');
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
}