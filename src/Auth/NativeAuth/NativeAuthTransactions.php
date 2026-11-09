<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\Oidc\OidcProvider;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use PDO;
use RuntimeException;
use Throwable;

final readonly class NativeAuthTransactions
{
    private NativeAuthRepository $rows;
    private NativeAuthGuard $guard;
    private RateLimiter $limits;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->rows = new NativeAuthRepository($pdo);
        $this->guard = new NativeAuthGuard($pdo, $config);
        $this->limits = new RateLimiter($pdo, $config->rateLimits);
    }

    /** @param array<string,mixed> $payload
     * @return array{transaction_id:string,browser_url:string,expires_at:string}
     */
    public function start(array $payload, ?AuthenticatedUser $actor, string $ip): array
    {
        NativeAuthConfiguration::requireConfigured($this->config);
        $client = $this->string($payload, 'client_id');
        $redirect = NativeAuthConfiguration::redirect($this->config, $client);
        if (array_key_exists('redirect_uri', $payload) && $payload['redirect_uri'] !== $redirect) {
            throw new ApiException(400, 'redirect_mismatch', 'redirect_uri must exactly match the approved app return URI.');
        }
        $provider = OidcProvider::require($this->config, $this->string($payload, 'provider'));
        $purpose = $this->string($payload, 'purpose');
        if (!in_array($purpose, ['login', 'restore', 'link', 'step_up', 'picture'], true)) {
            throw new ApiException(400, 'validation_error', 'Unsupported native sign-in purpose.');
        }
        $needsActor = in_array($purpose, ['link', 'step_up', 'picture'], true);
        if ($actor?->guest || $needsActor !== ($actor !== null)) {
            throw new ApiException(409, 'native_session_changed', 'Start this purpose from the appropriate app account session.');
        }
        $challenge = $this->string($payload, 'code_challenge');
        if (($payload['code_challenge_method'] ?? null) !== 'S256' || !NativeAuthConfiguration::validToken($challenge)) {
            throw new ApiException(400, 'validation_error', 'Use S256 with a canonical 32-byte code_challenge.');
        }
        $state = $this->string($payload, 'state');
        NativeAuthConfiguration::requireState($state);
        $binding = NativeAuthSession::nativeHash(true);
        if ($binding === null) throw new RuntimeException('Native binding was not created.');
        $this->limits->consume('native_auth_start', 'ip:' . $ip);
        $this->limits->consume('native_auth_start', 'binding:' . $binding);
        if ($purpose === 'link' && $actor !== null) SessionManager::requirePrivilegedStepUp($actor, $this->config);
        if ($purpose === 'step_up' && $actor !== null && (new MfaService($this->pdo, $this->config))->requiresMfaForLogin($actor)) {
            throw new ApiException(403, 'passkey_step_up_required', 'Use your passkey or recovery code in the app.');
        }
        $id = NativeAuthConfiguration::token();
        $entry = NativeAuthConfiguration::token();
        if ($this->pdo->inTransaction()) throw new RuntimeException('Native start must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $this->rows->statement('SELECT pg_advisory_xact_lock(hashtextextended(:binding, 146))', ['binding' => $binding]);
            $this->guard->lockAccountPolicy($actor?->id);
            if ($actor !== null) {
                $fresh = (new UserRepository($this->pdo))->findAuthenticatedById($actor->id);
                if ($fresh === null || $fresh->sessionVersion !== $actor->sessionVersion
                    || (new UserRepository($this->pdo))->activeBan($actor->id) !== null) {
                    throw NativeAuthGuard::failure('account_changed');
                }
            }
            if ($purpose === 'link' && $actor !== null) SessionManager::requirePrivilegedStepUp($actor, $this->config);
            if ($purpose === 'step_up' && $actor !== null && (new MfaService($this->pdo, $this->config))->requiresMfaForLogin($actor)) {
                throw new ApiException(403, 'passkey_step_up_required', 'Use your passkey or recovery code in the app.');
            }
            $this->rows->supersedeBinding($binding);
            $created = $this->rows->insert([
                'id' => $id, 'native_binding_hash' => $binding, 'entry_hash' => hash('sha256', $entry),
                'state_hash' => hash('sha256', $state), 'return_state' => $state, 'code_challenge' => $challenge,
                'client_id' => $client, 'redirect_uri' => $redirect,
                'config_hash' => NativeAuthConfiguration::fingerprint($this->config),
                'provider' => $provider->name, 'purpose' => $purpose, 'user_id' => $actor?->id,
                'user_version' => $actor?->sessionVersion, 'ttl' => NativeAuthConfiguration::TRANSACTION_TTL,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        // A new primary auth intent replaces older password/reset/provider continuations in this app jar.
        if (in_array($purpose, ['login', 'restore'], true)) {
            unset($_SESSION['pending_mfa'], $_SESSION['pending_password_reset'], $_SESSION['password_reset_challenge'],
                $_SESSION['oidc_sign_up'], $_SESSION['oidc_flows'], $_SESSION['webauthn_ceremony']);
        }
        NativeAuthSession::clearContinuation();
        unset($_SESSION['native_auth_browser']);
        return ['transaction_id' => $id,
            'browser_url' => $this->config->applicationOrigin . '/native-auth.php?' . http_build_query(
                ['transaction_id' => $id, 'entry' => $entry], '', '&', PHP_QUERY_RFC3986),
            'expires_at' => (new \DateTimeImmutable($created['expires_at']))->format('Y-m-d\TH:i:s.uP')];
    }

    /** @return array{transaction_id:string,phase:string,outcome:?string,error_code:?string} */
    public function status(string $id, string $ip): array
    {
        $binding = NativeAuthSession::nativeHash();
        if ($binding === null) throw NativeAuthGuard::failure('native_session_changed');
        $this->limits->consume('native_auth_status', 'ip:' . $ip);
        $this->limits->consume('native_auth_status', 'binding:' . $binding);
        $row = $this->rows->require($id);
        $this->guard->nativeOwner($row);
        return ['transaction_id' => $id, 'phase' => $row['phase'] === 'consumed' ? 'used' : (string) $row['phase'],
            'outcome' => is_string($row['outcome']) ? $row['outcome'] : null,
            'error_code' => $this->guard->errorCode($row)];
    }

    /** @return array{status:string} */
    public function cancel(string $id, string $ip): array
    {
        $binding = NativeAuthSession::nativeHash();
        if ($binding === null) throw NativeAuthGuard::failure('native_session_changed');
        $this->limits->consume('native_auth_browser', 'native-cancel:' . $binding . '|' . $ip);
        if ($this->pdo->inTransaction()) throw new RuntimeException('Native cancellation must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $row = $this->rows->require($id, true);
            $this->guard->nativeOwner($row);
            if ($row['phase'] === 'consumed') throw NativeAuthGuard::failure('already_used');
            if (in_array($row['phase'], ['superseded', 'expired'], true)) {
                throw NativeAuthGuard::failure((string) ($row['error_code'] ?? $row['phase']));
            }
            if ($row['phase'] !== 'cancelled') $this->rows->terminal($id, 'cancelled');
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
        $context = NativeAuthSession::continuation();
        if ($context !== null && $context['transaction_id'] === $id) NativeAuthSession::clearContinuation();
        return ['status' => 'cancelled'];
    }

    /** @param array<string,mixed> $payload */
    public function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 512) {
            throw new ApiException(400, 'validation_error', $key . ' must be a bounded non-empty string.');
        }
        return $value;
    }
    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }}
