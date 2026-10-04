<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

use ChitChat\Admin\LockdownService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Signing in with Google or Twitch via OpenID Connect: the authorization-code
 * flow with PKCE, a state bound to this browser session, and a nonce bound
 * into the ID token. Only the "openid" scope is requested and only the
 * provider plus its subject identifier is stored.
 */
final class OidcService
{
    private const FLOW_TTL_SECONDS = 600;
    private const MAX_PENDING_FLOWS = 5;
    private const KEY_CACHE_SECONDS = 3600;

    private readonly UserRepository $users;
    private readonly AuditLogger $audit;
    private readonly IdTokenVerifier $verifier;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly OidcHttpClient $http = new CurlOidcHttpClient(),
    ) {
        $this->users = new UserRepository($pdo);
        $this->audit = new AuditLogger($pdo);
        $this->verifier = new IdTokenVerifier();
    }

    /** @return list<array{id:string, label:string}> */
    public function providers(): array
    {
        return array_values(array_map(
            static fn (OidcProvider $provider): array => ['id' => $provider->name, 'label' => $provider->label],
            OidcProvider::configured($this->config),
        ));
    }

    /**
     * Starts a sign-in ('login') or connects a provider to the signed-in
     * account ('link'), returning the provider URL to send the browser to.
     */
    public function begin(string $providerName, string $purpose, ?AuthenticatedUser $user): string
    {
        $provider = OidcProvider::require($this->config, $providerName);
        if (!in_array($purpose, ['login', 'link'], true) || ($purpose === 'link') !== ($user !== null)) {
            throw new ApiException(400, 'validation_error', 'Unsupported sign-in purpose.');
        }

        $state = $this->randomToken();
        $nonce = $this->randomToken();
        $verifier = $this->randomToken();
        $flows = $this->pendingFlows();
        $flows[$state] = [
            'provider' => $provider->name,
            'purpose' => $purpose,
            'user_id' => $user?->id,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'created_at' => time(),
        ];
        // Keep only the newest few, so abandoned attempts cannot pile up.
        uasort($flows, static fn (array $left, array $right): int => $right['created_at'] <=> $left['created_at']);
        $_SESSION['oidc_flows'] = array_slice($flows, 0, self::MAX_PENDING_FLOWS, true);

        $parameters = [
            'response_type' => 'code',
            'client_id' => $provider->clientId,
            'redirect_uri' => OidcProvider::redirectUri($this->config),
            'scope' => 'openid',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ];
        if ($provider->name === 'google') {
            $parameters['prompt'] = 'select_account';
        }

        return $provider->authorizationEndpoint . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Finishes a sign-in attempt from the provider's redirect and returns the
     * page to send the browser to next.
     *
     * @param array<string, mixed> $query
     */
    public function complete(array $query, string $ipAddress): string
    {
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $flows = $this->pendingFlows();
        $flow = $flows[$state] ?? null;
        unset($flows[$state]);
        $_SESSION['oidc_flows'] = $flows;
        if ($flow === null) {
            throw new ApiException(400, 'oidc_flow_expired', 'This sign-in attempt has expired or was already used. Please start again.');
        }
        $provider = OidcProvider::require($this->config, (string) $flow['provider']);
        $returnTo = $flow['purpose'] === 'link' ? '/account.php' : '/';
        if (isset($query['error'])) {
            throw new OidcRedirectException($returnTo, sprintf('Signing in with %s was cancelled.', $provider->label));
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '') {
            throw new OidcRedirectException($returnTo, sprintf('%s did not return a sign-in code.', $provider->label));
        }

        try {
            $subject = $this->subjectFor($provider, $code, (string) $flow['verifier'], (string) $flow['nonce']);
        } catch (ApiException $exception) {
            throw new OidcRedirectException($returnTo, $exception->getMessage());
        }

        return $flow['purpose'] === 'link'
            ? $this->link((int) $flow['user_id'], $provider, $subject, $ipAddress)
            : $this->signIn($provider, $subject, $ipAddress);
    }

    /** @return list<array{provider:string, label:string, linked_at:string, last_used_at:?string}> */
    public function identities(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT provider, created_at, last_used_at FROM user_identities WHERE user_id = :user_id ORDER BY provider',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare identity lookup.');
        }
        $statement->execute(['user_id' => $userId]);
        $labels = ['google' => 'Google', 'twitch' => 'Twitch'];
        $identities = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identities[] = [
                'provider' => (string) $row['provider'],
                'label' => $labels[(string) $row['provider']] ?? (string) $row['provider'],
                'linked_at' => (string) $row['created_at'],
                'last_used_at' => $row['last_used_at'] === null ? null : (string) $row['last_used_at'],
            ];
        }

        return $identities;
    }

    public function unlink(AuthenticatedUser $actor, string $providerName, string $ipAddress): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_identities WHERE user_id = :user_id AND provider = :provider');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare identity removal.');
        }
        $statement->execute(['user_id' => $actor->id, 'provider' => $providerName]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException(404, 'identity_not_found', 'That sign-in provider is not connected to your account.');
        }
        $this->audit->log($actor->id, 'account.identity_unlinked', 'user', (string) $actor->id, ['provider' => $providerName], $ipAddress);
    }

    private function signIn(OidcProvider $provider, string $subject, string $ipAddress): string
    {
        (new RateLimiter($this->pdo, $this->config->rateLimits))->consume('oidc_sign_in', 'ip:' . $ipAddress);
        $userId = $this->userIdFor($provider->name, $subject);
        if ($userId === null) {
            throw new OidcRedirectException('/', sprintf(
                'No account here is connected to this %1$s account yet. Sign in with your password, then connect %1$s on your Account page.',
                $provider->label,
            ));
        }
        $user = $this->users->findAuthenticatedById($userId);
        if ($user === null) {
            throw new OidcRedirectException('/', 'This account is not available for sign-in. If it is scheduled for closure, use “Restore a closing account”.');
        }
        if ($this->users->activeBan($user->id) !== null) {
            throw new OidcRedirectException('/', 'This account is banned.');
        }
        try {
            (new LockdownService($this->pdo))->assertSignInAllowed($user);
        } catch (ApiException $exception) {
            throw new OidcRedirectException('/', $exception->getMessage());
        }

        $this->touch($provider->name, $subject);
        $this->audit->log($user->id, 'auth.oidc_sign_in', 'user', (string) $user->id, ['provider' => $provider->name], $ipAddress);
        // Multi-factor authentication applies exactly as after a password.
        if ((new MfaService($this->pdo, $this->config))->requiresMfaForLogin($user)) {
            SessionManager::beginMfaLogin($user, $ipAddress, $this->config->mfaPendingLoginTtlSeconds);
            return '/?mfa=continue';
        }
        (new AuthService($this->pdo, $this->config))->completeLogin($user, $ipAddress);
        SessionManager::login($user);

        return '/';
    }

    private function link(int $userId, OidcProvider $provider, string $subject, string $ipAddress): string
    {
        $current = SessionManager::currentUser($this->users);
        if ($current === null || $current->id !== $userId) {
            throw new OidcRedirectException('/', 'Your session changed while connecting. Please sign in and try again.');
        }
        $existing = $this->userIdFor($provider->name, $subject);
        if ($existing !== null && $existing !== $userId) {
            throw new OidcRedirectException('/account.php', sprintf('This %s account is already connected to another account here.', $provider->label));
        }
        if ($existing === $userId) {
            return '/account.php?connected=' . rawurlencode($provider->name);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO user_identities (user_id, provider, subject) VALUES (:user_id, :provider, :subject)',
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare identity link.');
        }
        try {
            $statement->execute(['user_id' => $userId, 'provider' => $provider->name, 'subject' => $subject]);
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23505') {
                throw new OidcRedirectException('/account.php', sprintf('Disconnect your current %s account first.', $provider->label));
            }
            throw $exception;
        }
        $this->audit->log($userId, 'account.identity_linked', 'user', (string) $userId, ['provider' => $provider->name], $ipAddress);

        return '/account.php?connected=' . rawurlencode($provider->name);
    }

    private function subjectFor(OidcProvider $provider, string $code, string $verifier, string $nonce): string
    {
        $tokens = $this->http->postForm($provider->tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => OidcProvider::redirectUri($this->config),
            'client_id' => $provider->clientId,
            'client_secret' => $provider->clientSecret,
            'code_verifier' => $verifier,
        ]);
        $idToken = $tokens['id_token'] ?? null;
        if (!is_string($idToken) || $idToken === '') {
            throw new ApiException(502, 'sign_in_provider_failed', sprintf('%s did not return an identity token.', $provider->label));
        }

        try {
            $claims = $this->verifier->verify($idToken, $provider, $nonce, $this->signingKeys($provider, false));
        } catch (ApiException $exception) {
            if ($exception->errorCode !== 'oidc_unknown_key') {
                throw $exception;
            }
            // Providers rotate keys; fetch them afresh once before giving up.
            $claims = $this->verifier->verify($idToken, $provider, $nonce, $this->signingKeys($provider, true));
        }

        return (string) $claims['sub'];
    }

    /** @return array<string, mixed> */
    private function signingKeys(OidcProvider $provider, bool $refresh): array
    {
        if (!$refresh) {
            $statement = $this->pdo->prepare(
                'SELECT jwks_json::text AS jwks FROM oidc_signing_keys WHERE provider = :provider AND fetched_at > NOW() - make_interval(secs => :max_age)',
            );
            if ($statement === false) {
                throw new RuntimeException('Unable to prepare signing-key lookup.');
            }
            $statement->execute(['provider' => $provider->name, 'max_age' => self::KEY_CACHE_SECONDS]);
            $cached = $statement->fetchColumn();
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $keys = $this->http->getJson($provider->jwksUri);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO oidc_signing_keys (provider, jwks_json, fetched_at)
VALUES (:provider, CAST(:jwks AS jsonb), NOW())
ON CONFLICT (provider) DO UPDATE SET jwks_json = EXCLUDED.jwks_json, fetched_at = EXCLUDED.fetched_at
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare signing-key cache.');
        }
        $statement->execute(['provider' => $provider->name, 'jwks' => json_encode($keys, JSON_THROW_ON_ERROR)]);

        return $keys;
    }

    private function userIdFor(string $provider, string $subject): ?int
    {
        $statement = $this->pdo->prepare('SELECT user_id FROM user_identities WHERE provider = :provider AND subject = :subject');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare identity lookup.');
        }
        $statement->execute(['provider' => $provider, 'subject' => $subject]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function touch(string $provider, string $subject): void
    {
        $statement = $this->pdo->prepare('UPDATE user_identities SET last_used_at = NOW() WHERE provider = :provider AND subject = :subject');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare identity update.');
        }
        $statement->execute(['provider' => $provider, 'subject' => $subject]);
    }

    /** @return array<string, array{provider:string, purpose:string, user_id:?int, nonce:string, verifier:string, created_at:int}> */
    private function pendingFlows(): array
    {
        $stored = $_SESSION['oidc_flows'] ?? [];
        $flows = [];
        foreach (is_array($stored) ? $stored : [] as $state => $flow) {
            if (
                !is_string($state)
                || !is_array($flow)
                || !is_string($flow['provider'] ?? null)
                || !is_string($flow['purpose'] ?? null)
                || !is_string($flow['nonce'] ?? null)
                || !is_string($flow['verifier'] ?? null)
                || !is_int($flow['created_at'] ?? null)
                || $flow['created_at'] <= time() - self::FLOW_TTL_SECONDS
            ) {
                continue;
            }
            $flows[$state] = [
                'provider' => $flow['provider'],
                'purpose' => $flow['purpose'],
                'user_id' => is_int($flow['user_id'] ?? null) ? $flow['user_id'] : null,
                'nonce' => $flow['nonce'],
                'verifier' => $flow['verifier'],
                'created_at' => $flow['created_at'],
            ];
        }

        return $flows;
    }

    private function randomToken(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
