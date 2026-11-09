<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

final readonly class NativeAuthExchange
{
    private NativeAuthRepository $rows;
    private NativeAuthGuard $guard;
    /** @var Closure(NativeAuthOutcome,string):array<string,mixed>|null */
    private ?Closure $installer;

    /** The optional installer permits session-persistence failure proofs without any HTTP test bypass.
     * @param (callable(NativeAuthOutcome,string):array<string,mixed>)|null $installer
     */
    public function __construct(private PDO $pdo, private Config $config, ?callable $installer = null)
    {
        $this->rows = new NativeAuthRepository($pdo);
        $this->guard = new NativeAuthGuard($pdo, $config);
        $this->installer = $installer === null ? null : Closure::fromCallable($installer);
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function exchange(#[\SensitiveParameter] array $payload, string $ip): array
    {
        $id = is_string($payload['transaction_id'] ?? null) ? $payload['transaction_id'] : '';
        $binding = NativeAuthSession::nativeHash();
        if ($binding === null) throw NativeAuthGuard::failure('native_session_changed');
        $limits = new RateLimiter($this->pdo, $this->config->rateLimits);
        $limits->consume('native_auth_exchange', 'ip:' . $ip);
        $limits->consume('native_auth_exchange', 'binding:' . $binding);
        $snapshot = $this->rows->require($id);
        $this->guard->nativeOwner($snapshot);
        $this->proofOfExchange($snapshot, $payload);
        if ($this->pdo->inTransaction()) throw new RuntimeException('Native exchange must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $this->guard->lockAccountPolicy($snapshot['user_id'] === null ? null : (int) $snapshot['user_id'], $snapshot['purpose'] === 'restore');
            $row = $this->rows->require($id, true);
            $this->guard->nativeOwner($row);
            $error = $this->guard->errorCode($row);
            if (in_array($error, ['expired', 'code_expired', 'native_config_changed'], true)) {
                $this->rows->terminal($id, 'expired', $error);
                $this->pdo->commit();
                throw NativeAuthGuard::failure($error);
            }
            $this->proofOfExchange($row, $payload);
            if ($row['user_id'] !== $snapshot['user_id'] || $row['user_version'] !== $snapshot['user_version']) {
                throw NativeAuthGuard::failure('account_changed');
            }
            $actor = SessionManager::currentUser(new UserRepository($this->pdo));
            $this->guard->nativeOwner($row); // currentUser may invalidate a stale PHP authentication context.
            $this->guard->nativeIdentity($row, $actor);
            $proof = $this->guard->proof($row);
            $outcome = (new NativeAuthEffects($this->pdo, $this->config))->apply($row, $proof, $ip);
            // A dependency lock may have waited past a deadline. Roll back effects as well as consumption.
            $lateError = $this->guard->errorCode($row);
            if ($lateError !== null) throw NativeAuthGuard::failure($lateError);
            $this->rows->consume($id, $outcome->outcome);
            (new AuditLogger($this->pdo))->log($outcome->user?->id, 'auth.native_exchange',
                'native_auth_transaction', $id, ['purpose' => $outcome->purpose,
                    'client_id' => (string) $row['client_id'], 'outcome' => $outcome->outcome], $ip);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        // Deliberately outside PostgreSQL's transaction; a lost cookie/write cannot re-issue this grant.
        return $this->installer === null
            ? $outcome->install($this->config, $ip, new OidcService($this->pdo, $this->config))
            : ($this->installer)($outcome, $ip);
    }

    /** @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     */
    private function proofOfExchange(array $row, #[\SensitiveParameter] array $payload): void
    {
        $state = $payload['state'] ?? null;
        if (!is_string($state) || strlen($state) < 32 || strlen($state) > 128
            || !hash_equals((string) $row['state_hash'], hash('sha256', $state))) {
            throw NativeAuthGuard::failure('state_mismatch');
        }
        $error = $this->guard->errorCode($row);
        if ($error !== null) throw NativeAuthGuard::failure($error);
        if ($row['phase'] !== 'staged') throw new ApiException(409, 'result_not_ready', 'Approve this request in the browser first.');
        $code = $payload['code'] ?? null;
        if (!is_string($code) || !NativeAuthConfiguration::validToken($code)
            || !is_string($row['code_hash']) || !hash_equals($row['code_hash'], hash('sha256', $code))) {
            throw new ApiException(403, 'code_mismatch', 'The return code does not match this request.');
        }
        $verifier = $payload['code_verifier'] ?? null;
        if (!is_string($verifier) || !NativeAuthConfiguration::verifierMatches($verifier, (string) $row['code_challenge'])) {
            throw NativeAuthGuard::failure('verifier_mismatch');
        }
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
}
