<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\Oidc\OidcHttpClient;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/** @phpstan-import-type ConfirmationView from NativeAuthService */
final readonly class NativeAuthBrowser
{
    private NativeAuthRepository $rows;
    private NativeAuthGuard $guard;
    private NativeAuthViewModel $views;

    public function __construct(private PDO $pdo, private Config $config, private OidcHttpClient $http)
    {
        $this->rows = new NativeAuthRepository($pdo);
        $this->guard = new NativeAuthGuard($pdo, $config);
        $this->views = new NativeAuthViewModel($pdo, $config);
    }

    /** This method never starts or rotates PHP's session.
     * @return array{view:ConfirmationView,cookie:string,remaining:int}
     */
    public function prepareEntryBootstrap(string $id, #[\SensitiveParameter] string $entry): array
    {
        NativeAuthConfiguration::requireConfigured($this->config);
        $result = $this->transaction(function () use ($id, $entry): array {
            $row = $this->rows->require($id, true);
            $this->entryProof($row, $entry);
            $this->usable($row);
            if ($row['browser_binding_hash'] !== null || $row['bootstrap_hash'] !== null) throw NativeAuthGuard::failure('already_used');
            $bootstrap = NativeAuthBootstrap::create($id, 'entry');
            $this->rows->statement("UPDATE native_auth_transactions SET entry_hash = NULL, bootstrap_hash = :hash, bootstrap_kind = 'entry', bootstrap_expires_at = expires_at WHERE id = :id",
                ['id' => $id, 'hash' => $bootstrap['hash']]);
            return $bootstrap;
        });
        $row = $this->rows->require($id);
        return ['view' => $this->views->model($row, 'entry', null, $result['csrf_token'], bootstrapKind: 'entry'),
            'cookie' => $result['cookie'], 'remaining' => $this->remaining((string) $row['expires_at'])];
    }

    public function enterWithSession(string $id, #[\SensitiveParameter] string $entry): void
    {
        $actor = $this->actor();
        $this->transaction(function () use ($id, $entry, $actor): void {
            $row = $this->rows->require($id, true);
            if ($row['browser_binding_hash'] !== null) {
                $this->guard->browserOwner($row, $actor);
                return;
            }
            $this->entryProof($row, $entry);
            $this->usable($row);
            if ($row['bootstrap_hash'] !== null) throw NativeAuthGuard::failure('already_used');
            $this->bind($row, $actor);
        });
    }

    /** Validate before SessionManager::start(), so a bogus bootstrap never replaces a withheld SID.
     * @return array{id:string,kind:string,hash:string,csrf_token:string}
     */
    public function validateBootstrap(#[\SensitiveParameter] string $cookie, #[\SensitiveParameter] string $csrf, string $kind): array
    {
        $proof = NativeAuthBootstrap::verify($cookie, $csrf, $kind);
        $row = $this->rows->require($proof['id']);
        $this->bootstrapProof($row, $proof, $kind);
        return $proof;
    }

    /** @return ConfirmationView */
    public function bootstrapView(#[\SensitiveParameter] string $cookie): array
    {
        $proof = NativeAuthBootstrap::parse($cookie);
        if ($proof === null) throw NativeAuthGuard::failure('expired');
        $row = $this->rows->require($proof['id']);
        $this->bootstrapProof($row, $proof, $proof['kind']);
        return $this->views->model($row, $proof['kind'] === 'callback' ? 'verifying' : 'entry', null,
            $proof['csrf_token'], bootstrapKind: $proof['kind']);
    }

    /** @param array{id:string,kind:string,hash:string,csrf_token:string} $proof */
    public function continueBootstrap(array $proof, string $ip): ?string
    {
        $actor = $this->actor(); // The original Strict SID has arrived. Do not regenerate it before mismatch detection.
        $phase = $this->transaction(function () use ($proof, $actor): string {
            $row = $this->rows->require($proof['id'], true);
            $this->bootstrapProof($row, $proof, 'entry');
            return $this->bind($row, $actor);
        });
        return $phase === 'entry' ? $this->continueAction($ip) : null;
    }

    /** @return ConfirmationView */
    public function view(): array
    {
        $actor = $this->actor();
        $context = NativeAuthSession::browser($actor);
        if ($context === null) throw new ApiException(403, 'browser_session_changed', 'Start again from the app.');
        $row = $this->rows->require($context['transaction_id']);
        $this->guard->browserOwner($row, $actor);
        $error = $this->guard->errorCode($row);
        $phase = (string) $row['phase'];
        if ($phase === 'superseded' || in_array($error, ['expired', 'code_expired'], true)) $phase = 'expired';
        elseif ($error === 'native_config_changed') $phase = 'disabled';
        elseif ($phase === 'consumed') $phase = 'used';
        if ($phase !== 'staged') unset($_SESSION['native_auth_browser']['return_code']);
        $returnCode = $_SESSION['native_auth_browser']['return_code'] ?? null;
        return $this->views->model($row, $phase, is_string($row['outcome']) ? $row['outcome'] : null,
            SessionManager::csrfToken(), $actor, is_string($returnCode) ? $returnCode : null, authorizedStatus: true);
    }

    public function continueAction(string $ip): ?string
    {
        $snapshot = $this->current();
        $this->limit($ip, (string) $snapshot['id']);
        return $this->lockedBrowser($snapshot, function (array $row): ?string {
            if ($row['phase'] !== 'entry') return null;
            $target = in_array($row['purpose'], ['link', 'step_up', 'picture'], true) ? $this->guard->account($row) : null;
            $url = (new OidcService($this->pdo, $this->config, $this->http))->begin(
                (string) $row['provider'], (string) $row['purpose'], $target, (string) $row['id']);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
            $state = $parameters['state'] ?? null;
            if (!is_string($state) || !str_starts_with($state, 'native.')
                || !NativeAuthConfiguration::validToken(substr($state, 7))) throw new RuntimeException('Native provider state was not created.');
            $this->rows->statement("UPDATE native_auth_transactions SET phase = 'verifying', provider_state_hash = :hash WHERE id = :id",
                ['id' => (string) $row['id'], 'hash' => hash('sha256', $state)]);
            return $url;
        });
    }

    public function restart(string $ip): void
    {
        $snapshot = $this->current();
        $this->limit($ip, (string) $snapshot['id']);
        $this->lockedBrowser($snapshot, function (array $row): void {
            if ($row['phase'] === 'account_mismatch') {
                $this->rows->statement("UPDATE native_auth_transactions SET mismatch_acknowledged = TRUE, phase = CASE WHEN proof IS NULL THEN 'entry' ELSE 'consent' END WHERE id = :id",
                    ['id' => (string) $row['id']]);
                return;
            }
            if (!in_array($row['phase'], ['verifying', 'identity_mismatch'], true)) return;
            $this->rows->statement(<<<'SQL'
UPDATE native_auth_transactions SET phase = 'entry', proof = NULL, picture = NULL, subject_hash = NULL,
 provider_state_hash = NULL, callback_query = NULL, callback_expires_at = NULL,
 bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL,
 user_id = CASE WHEN purpose IN ('login','restore') THEN NULL ELSE user_id END,
 user_version = CASE WHEN purpose IN ('login','restore') THEN NULL ELSE user_version END
WHERE id = :id
SQL, ['id' => (string) $row['id']]);
        });
    }

    public function approve(string $ip): void
    {
        $snapshot = $this->current();
        $this->limit($ip, (string) $snapshot['id']);
        $code = $this->lockedBrowser($snapshot, function (array $row): ?string {
            if ($row['phase'] === 'staged') return null; // Never issue a replacement code or extend its lifetime.
            if ($row['phase'] !== 'consent') throw new ApiException(409, 'consent_required', 'Verify the provider and approve this request first.');
            $this->guard->proof($row);
            if ($row['user_id'] !== null) $this->guard->account($row);
            $code = NativeAuthConfiguration::token();
            $this->rows->statement("UPDATE native_auth_transactions SET phase = 'staged', code_hash = :hash, code_expires_at = LEAST(expires_at, clock_timestamp() + make_interval(secs => :ttl)) WHERE id = :id",
                ['id' => (string) $row['id'], 'hash' => hash('sha256', $code), 'ttl' => NativeAuthConfiguration::CODE_TTL]);
            return $code;
        });
        if ($code !== null) $_SESSION['native_auth_browser']['return_code'] = $code;
    }

    public function cancel(string $ip): void
    {
        $actor = $this->actor();
        $context = NativeAuthSession::browser($actor);
        if ($context === null) throw new ApiException(403, 'browser_session_changed', 'Start again from the app.');
        $this->limit($ip, $context['transaction_id']);
        $this->transaction(function () use ($context, $actor): void {
            $row = $this->rows->require($context['transaction_id'], true);
            $this->guard->browserOwner($row, $actor);
            if ($row['phase'] === 'consumed') throw NativeAuthGuard::failure('already_used');
            if (!in_array($row['phase'], ['cancelled', 'superseded', 'expired'], true)) $this->rows->terminal((string) $row['id'], 'cancelled');
        });
        unset($_SESSION['native_auth_browser']['return_code']);
    }

    /** @param array{id:string,kind:string,hash:string,csrf_token:string} $proof */
    public function cancelBootstrap(array $proof, string $ip): void
    {
        $actor = $this->actor();
        $this->limit($ip, $proof['id']);
        $this->transaction(function () use ($proof, $actor): void {
            $row = $this->rows->require($proof['id'], true);
            $this->bootstrapProof($row, $proof, $proof['kind']);
            if ($proof['kind'] === 'callback') $this->guard->browserOwner($row, $actor);
            else $this->bind($row, $actor);
            $this->rows->terminal($proof['id'], 'cancelled');
        });
    }

    /** @return array<string,mixed> */
    public function current(): array
    {
        $actor = $this->actor();
        $context = NativeAuthSession::browser($actor);
        if ($context === null) throw new ApiException(403, 'browser_session_changed', 'Start again from the app.');
        $row = $this->rows->require($context['transaction_id']);
        $this->guard->browserOwner($row, $actor);
        return $row;
    }

    /** @param array<string,mixed> $row */
    private function entryProof(array $row, #[\SensitiveParameter] string $entry): void
    {
        if (!NativeAuthConfiguration::validToken($entry) || !is_string($row['entry_hash'])
            || !hash_equals($row['entry_hash'], hash('sha256', $entry))) {
            throw new ApiException(403, 'entry_mismatch', 'This entry link is invalid or already used.');
        }
    }

    /** @param array<string,mixed> $row
     * @param array{id:string,kind:string,hash:string,csrf_token:string} $proof
     */
    public function bootstrapProof(array $row, array $proof, string $kind): void
    {
        if ($row['id'] !== $proof['id'] || $row['bootstrap_kind'] !== $kind || $proof['kind'] !== $kind
            || !is_string($row['bootstrap_hash']) || !hash_equals($row['bootstrap_hash'], $proof['hash'])) {
            throw new ApiException(403, 'bootstrap_mismatch', 'This browser entry is unavailable.');
        }
        $this->usable($row);
        if ($row['bootstrap_expires_at'] === null || new DateTimeImmutable((string) $row['bootstrap_expires_at']) <= $this->guard->now()) {
            throw NativeAuthGuard::failure('expired');
        }
    }

    /** @param array<string,mixed> $row */
    private function bind(array $row, ?AuthenticatedUser $actor): string
    {
        if ($row['browser_binding_hash'] !== null) throw NativeAuthGuard::failure('already_used');
        $mismatch = $actor !== null && $row['user_id'] !== null && $actor->id !== (int) $row['user_id'];
        $phase = $mismatch ? 'account_mismatch' : 'entry';
        $binding = NativeAuthSession::bindBrowser((string) $row['id'], $actor);
        $this->rows->statement('UPDATE native_auth_transactions SET browser_binding_hash = :binding, entry_hash = NULL,
            bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL, phase = :phase WHERE id = :id',
            ['id' => (string) $row['id'], 'binding' => $binding, 'phase' => $phase]);
        return $phase;
    }

    /** @param array<string,mixed> $row */
    private function usable(array $row): void
    {
        $error = $this->guard->errorCode($row);
        if ($error !== null) throw NativeAuthGuard::failure($error);
    }

    private function remaining(string $expires): int
    {
        $remaining = (int) floor((float) (new DateTimeImmutable($expires))->format('U.u') - (float) $this->guard->now()->format('U.u'));
        if ($remaining < 1) throw NativeAuthGuard::failure('expired');
        return min(NativeAuthConfiguration::TRANSACTION_TTL, $remaining);
    }

    private function actor(): ?AuthenticatedUser
    {
        return SessionManager::currentUser(new UserRepository($this->pdo));
    }

    private function limit(string $ip, string $id): void
    {
        $limits = new RateLimiter($this->pdo, $this->config->rateLimits);
        $limits->consume('native_auth_browser', 'ip:' . $ip);
        $limits->consume('native_auth_browser', 'transaction:' . $id);
    }

    /** @template T
     * @param callable():T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) throw new RuntimeException('Native browser operation must own its transaction.');
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    /** @template T
     * @param array<string,mixed> $snapshot
     * @param callable(array<string,mixed>):T $operation
     * @return T
     */
    private function lockedBrowser(array $snapshot, callable $operation): mixed
    {
        return $this->transaction(function () use ($snapshot, $operation): mixed {
            $this->guard->lockAccountPolicy($snapshot['user_id'] === null ? null : (int) $snapshot['user_id'], $snapshot['purpose'] === 'restore');
            $row = $this->rows->require((string) $snapshot['id'], true);
            $this->guard->browserOwner($row, $this->actor());
            if ($row['user_id'] !== $snapshot['user_id'] || $row['user_version'] !== $snapshot['user_version']) throw NativeAuthGuard::failure('account_changed');
            $this->usable($row);
            return $operation($row);
        });
    }

    private function rollBack(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }
}
