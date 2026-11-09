<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Account\AvatarService;
use ChitChat\Auth\Oidc\OidcHttpClient;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\Oidc\OidcVerification;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use DateTimeImmutable;
use PDO;

/** Provider HTTP runs between short metadata-claim and proof-staging transactions. */
final readonly class NativeAuthProviderFlow
{
    private NativeAuthRepository $rows;
    private NativeAuthGuard $guard;
    private NativeAuthBrowser $browser;

    public function __construct(private PDO $pdo, private Config $config, private OidcHttpClient $http)
    {
        $this->rows = new NativeAuthRepository($pdo);
        $this->guard = new NativeAuthGuard($pdo, $config);
        $this->browser = new NativeAuthBrowser($pdo, $config, $http);
    }

    /** Safe before PHP session startup. Unknown, expired and already-claimed callbacks fail closed.
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    public function recognize(#[\SensitiveParameter] array $query): array
    {
        $state = $query['state'] ?? null;
        $row = is_string($state) ? $this->rows->forProviderState($state) : null;
        if ($row === null) throw new ApiException(400, 'native_callback_unavailable', 'Start again from the app.');
        $this->callbackReady($row);
        return $row;
    }

    /** No PHP session, provider request or account mutation takes place here.
     * @param array<string,mixed> $query
     * @return array{cookie:string,remaining:int}
     */
    public function park(#[\SensitiveParameter] array $query, string $ip): array
    {
        $snapshot = $this->recognize($query);
        $this->limit($ip, (string) $snapshot['id']);
        $filtered = $this->boundedQuery($query);
        return $this->browser->transaction(function () use ($snapshot, $filtered): array {
            $row = $this->rows->require((string) $snapshot['id'], true);
            $this->sameAttempt($row, $snapshot);
            $this->callbackReady($row);
            $bootstrap = NativeAuthBootstrap::create((string) $row['id'], 'callback');
            $expires = $this->rows->statement(<<<'SQL'
UPDATE native_auth_transactions SET callback_query = CAST(:query AS jsonb),
 callback_expires_at = LEAST(expires_at, clock_timestamp() + interval '5 minutes'),
 bootstrap_hash = :hash, bootstrap_kind = 'callback',
 bootstrap_expires_at = LEAST(expires_at, clock_timestamp() + interval '5 minutes')
WHERE id = :id RETURNING bootstrap_expires_at
SQL, ['query' => json_encode($filtered, JSON_THROW_ON_ERROR), 'hash' => $bootstrap['hash'], 'id' => $row['id']])->fetchColumn();
            $remaining = is_string($expires) ? (int) floor((float) (new DateTimeImmutable($expires))->format('U.u') - (float) $this->guard->now()->format('U.u')) : 0;
            if ($remaining < 1) throw NativeAuthGuard::failure('expired');
            return ['cookie' => $bootstrap['cookie'], 'remaining' => $remaining];
        });
    }

    /** The received Lax session verifies directly, without a bootstrap interstitial.
     * @param array<string,mixed> $query
     */
    public function direct(#[\SensitiveParameter] array $query, string $ip): void
    {
        $snapshot = $this->recognize($query);
        $this->limit($ip, (string) $snapshot['id']);
        $filtered = $this->boundedQuery($query);
        $claim = $this->browser->transaction(function () use ($snapshot): string {
            $row = $this->rows->require((string) $snapshot['id'], true);
            $this->sameAttempt($row, $snapshot);
            $this->callbackReady($row);
            $this->guard->browserOwner($row, SessionManager::currentUser(new UserRepository($this->pdo)));
            return $this->claim($row);
        });
        $this->verifyAndStage($snapshot, [...$filtered, 'state' => (string) $query['state']], $claim, $ip);
    }

    /** Bootstrap proof was validated before startup; only the original browser binding may resume.
     * @param array{id:string,kind:string,hash:string,csrf_token:string} $proof
     */
    public function resume(array $proof, string $ip): void
    {
        $this->limit($ip, $proof['id']);
        $claimed = $this->browser->transaction(function () use ($proof): array {
            $row = $this->rows->require($proof['id'], true);
            $this->browser->bootstrapProof($row, $proof, 'callback');
            $this->guard->browserOwner($row, SessionManager::currentUser(new UserRepository($this->pdo)));
            $query = is_string($row['callback_query']) ? json_decode($row['callback_query'], true, 8, JSON_THROW_ON_ERROR) : null;
            if (!is_array($query) || isset($query['claim'])) throw NativeAuthGuard::failure('already_used');
            $state = $this->originalState($row);
            $claim = $this->claim($row); // Erase the parked code before HTTP; never retry a consumed callback.
            return ['row' => $row, 'query' => [...$this->boundedQuery($query), 'state' => $state], 'claim' => $claim];
        });
        $this->verifyAndStage($claimed['row'], $claimed['query'], $claimed['claim'], $ip);
    }

    /** @param array<string,mixed> $row */
    private function originalState(array $row): string
    {
        $flows = $_SESSION['oidc_flows'] ?? [];
        foreach (is_array($flows) ? $flows : [] as $state => $flow) {
            if (is_string($state) && is_array($flow) && ($flow['native_transaction'] ?? null) === $row['id']
                && is_string($row['provider_state_hash']) && hash_equals($row['provider_state_hash'], hash('sha256', $state))) return $state;
        }
        throw new ApiException(403, 'browser_session_changed', 'Return using the browser where this request started.');
    }

    /** @param array<string,mixed> $row */
    private function claim(array $row): string
    {
        $claim = NativeAuthConfiguration::token();
        $this->rows->statement(<<<'SQL'
UPDATE native_auth_transactions SET callback_query = CAST(:marker AS jsonb),
 callback_expires_at = LEAST(expires_at, clock_timestamp() + interval '5 minutes'),
 bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL WHERE id = :id
SQL, ['marker' => json_encode(['claim' => hash('sha256', $claim)], JSON_THROW_ON_ERROR), 'id' => $row['id']]);
        return $claim;
    }

    /** @param array<string,mixed> $snapshot
     * @param array<string,mixed> $query
     */
    private function verifyAndStage(array $snapshot, #[\SensitiveParameter] array $query, #[\SensitiveParameter] string $claim, string $ip): void
    {
        try {
            $verified = (new OidcService($this->pdo, $this->config, $this->http))->verifyCallback($query);
            if ($verified->nativeTransaction !== $snapshot['id'] || $verified->purpose !== $snapshot['purpose']
                || $verified->provider->name !== $snapshot['provider']
                || $verified->userId !== (in_array($snapshot['purpose'], ['link', 'step_up', 'picture'], true) ? (int) $snapshot['user_id'] : null)) {
                throw NativeAuthGuard::failure('identity_changed');
            }
            $subject = $verified->claims['sub'] ?? null;
            if (!is_string($subject) || $subject === '' || strlen($subject) > 255) throw NativeAuthGuard::failure('identity_changed');
            $linked = $this->guard->identityUser($verified->provider->name, $subject);
            $target = in_array($snapshot['purpose'], ['login', 'restore'], true) ? $linked : ($snapshot['user_id'] === null ? null : (int) $snapshot['user_id']);
            $picture = $this->picture($snapshot, $verified, $linked, $target);
            $this->browser->transaction(function () use ($snapshot, $claim, $verified, $subject, $target, $picture): void {
                $this->guard->lockAccountPolicy($target);
                $row = $this->rows->require((string) $snapshot['id'], true);
                $this->sameAttempt($row, $snapshot);
                $this->claimed($row, $claim);
                $actor = SessionManager::currentUser(new UserRepository($this->pdo));
                $this->guard->browserOwner($row, $actor);
                $linked = $this->guard->identityUser($verified->provider->name, $subject, true);
                if (in_array($row['purpose'], ['login', 'restore'], true) && $linked !== $target) throw NativeAuthGuard::failure('identity_changed');
                $mismatch = $row['purpose'] === 'link' ? ($linked !== null && $linked !== $target)
                    : (in_array($row['purpose'], ['step_up', 'picture', 'restore'], true) && ($target === null || $linked !== $target));
                $version = $row['user_version'] === null ? null : (int) $row['user_version'];
                if (!$mismatch && $target !== null) {
                    $user = $this->guard->account(array_replace($row, ['user_id' => $target,
                        'user_version' => $version ?? $this->version($target)]));
                    $version = $user?->sessionVersion;
                }
                $phase = $mismatch ? 'identity_mismatch' : 'consent';
                if (!$mismatch && $actor !== null && $target !== null && $actor->id !== $target && !(bool) $row['mismatch_acknowledged']) $phase = 'account_mismatch';
                $this->rows->stageProof((string) $row['id'], $phase, $target, $version,
                    ['subject' => $subject, 'auth_time' => is_int($verified->claims['auth_time'] ?? null) ? $verified->claims['auth_time'] : null,
                        'started_at' => $verified->startedAt], $mismatch ? null : $picture);
                $this->scrubCallback((string) $row['id']);
            });
        } catch (\Throwable $exception) {
            // Provider errors can contain response bodies. Persist no provider text or credentials.
            $this->browser->transaction(function () use ($snapshot, $claim): void {
                $row = $this->rows->require((string) $snapshot['id'], true);
                if (!$this->hasClaim($row, $claim)) return;
                $this->rows->statement("UPDATE native_auth_transactions SET phase = 'identity_mismatch' WHERE id = :id", ['id' => $row['id']]);
                $this->scrubCallback((string) $row['id']);
            });
            if ($exception instanceof ApiException && $exception->status === 429) throw $exception;
            // The confirmation page gives a safe restart path, never a raw provider error.
        }
    }

    private function version(int $id): int
    {
        $value = $this->rows->statement('SELECT session_version FROM users WHERE id = :id', ['id' => $id])->fetchColumn();
        if ($value === false) throw NativeAuthGuard::failure('account_changed');
        return (int) $value;
    }

    /** @param array<string,mixed> $snapshot */
    private function picture(array $snapshot, OidcVerification $verified, ?int $linked, ?int $target): ?string
    {
        if ($snapshot['purpose'] !== 'picture' || $target === null || $linked !== $target) return null;
        $url = $verified->provider->pictureUrl($verified->claims['picture'] ?? null);
        if ($url === null) throw new ApiException(422, 'invalid_avatar', 'The provider has no picture to use.');
        $bytes = $this->http->getBytes($url, AvatarService::IMPORT_MAX_BYTES);
        $info = strlen($bytes) <= AvatarService::IMPORT_MAX_BYTES ? @getimagesizefromstring($bytes) : false;
        if (!is_array($info) || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) throw new ApiException(422, 'invalid_avatar', 'This picture cannot be used.');
        return $bytes;
    }

    private function scrubCallback(string $id): void
    {
        $this->rows->statement('UPDATE native_auth_transactions SET provider_state_hash = NULL, callback_query = NULL,
            callback_expires_at = NULL, bootstrap_hash = NULL, bootstrap_kind = NULL, bootstrap_expires_at = NULL WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string,mixed> $row */
    private function callbackReady(array $row): void
    {
        $error = $this->guard->errorCode($row);
        if ($error !== null) throw NativeAuthGuard::failure($error);
        if ($row['phase'] !== 'verifying' || $row['callback_query'] !== null || $row['bootstrap_hash'] !== null) throw NativeAuthGuard::failure('already_used');
    }

    /** @param array<string,mixed> $row
     * @param array<string,mixed> $snapshot
     */
    private function sameAttempt(array $row, array $snapshot): void
    {
        if ($row['provider_state_hash'] !== $snapshot['provider_state_hash'] || $row['phase'] !== 'verifying') throw NativeAuthGuard::failure('superseded');
    }

    /** @param array<string,mixed> $row */
    private function hasClaim(array $row, string $claim): bool
    {
        $marker = is_string($row['callback_query']) ? json_decode($row['callback_query'], true, 8, JSON_THROW_ON_ERROR) : null;
        return is_array($marker) && is_string($marker['claim'] ?? null) && hash_equals($marker['claim'], hash('sha256', $claim));
    }

    /** @param array<string,mixed> $row */
    private function claimed(array $row, string $claim): void
    {
        $error = $this->guard->errorCode($row);
        if ($error !== null) throw NativeAuthGuard::failure($error);
        if (!$this->hasClaim($row, $claim)) throw NativeAuthGuard::failure('already_used');
    }

    /** Only these bounded provider fields may be parked; never tokens, state or verifier.
     * @param array<string,mixed> $query
     * @return array<string,string>
     */
    private function boundedQuery(#[\SensitiveParameter] array $query): array
    {
        $filtered = [];
        foreach (['code' => 4096, 'error' => 128, 'error_description' => 512] as $key => $maximum) {
            if (!array_key_exists($key, $query)) continue;
            if (!is_string($query[$key]) || strlen($query[$key]) > $maximum) throw new ApiException(400, 'native_callback_invalid', 'The provider response is invalid.');
            $filtered[$key] = $query[$key];
        }
        if (!isset($filtered['code']) && !isset($filtered['error'])) throw new ApiException(400, 'native_callback_invalid', 'The provider response is incomplete.');
        return $filtered;
    }

    private function limit(string $ip, string $id): void
    {
        $limits = new RateLimiter($this->pdo, $this->config->rateLimits);
        $limits->consume('native_auth_browser', 'ip:' . $ip);
        $limits->consume('native_auth_browser', 'transaction:' . $id);
    }
}