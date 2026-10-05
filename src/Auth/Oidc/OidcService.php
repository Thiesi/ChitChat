<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

use ChitChat\Account\AccountClosureService;
use ChitChat\Account\AvatarService;
use ChitChat\Admin\LockdownService;
use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\MfaRepository;
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
    private const PURPOSES = ['login', 'link', 'step_up', 'restore', 'picture'];
    /** Where each purpose returns to, including when it fails. */
    private const RETURN_TO = [
        'login' => '/',
        'link' => '/account.php',
        'step_up' => '/step-up-complete.php',
        'restore' => '/restore-account.php',
        // Marked, so the Profile picture card (not Sign-in methods) shows the error.
        'picture' => '/account.php?picture=failed',
    ];
    /** A provider-confirmed new account must be finished within this time. */
    private const SIGN_UP_TTL_SECONDS = 600;
    /** Clock skew allowed when checking that a step-up login was fresh. */
    private const AUTH_TIME_SKEW_SECONDS = 60;
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
     * Starts a provider round trip and returns the provider URL to send the
     * browser to: signing in or up ('login'), restoring a closing account
     * ('restore'), or, for the signed-in account, connecting a provider
     * ('link'), confirming a sensitive action ('step_up') or fetching its
     * profile picture there ('picture', the one round trip that asks for it).
     */
    public function begin(string $providerName, string $purpose, ?AuthenticatedUser $user): string
    {
        $provider = OidcProvider::require($this->config, $providerName);
        $needsUser = in_array($purpose, ['link', 'step_up', 'picture'], true);
        if (!in_array($purpose, self::PURPOSES, true) || $needsUser !== ($user !== null)) {
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
        if ($purpose === 'step_up') {
            // Ask for a fresh sign-in, not a silent pass on an old provider
            // session. Google reports auth_time, which complete() then checks;
            // Twitch has no such claim, so it shows its confirmation screen.
            if ($provider->name === 'google') {
                $parameters['max_age'] = '0';
            } else {
                $parameters['force_verify'] = 'true';
            }
        } elseif ($purpose === 'picture') {
            // Only this round trip asks for the picture; sign-in stays "openid" only.
            if ($provider->name === 'google') {
                $parameters['scope'] = 'openid profile';
            } else {
                $parameters['claims'] = '{"id_token":{"picture":null}}';
            }
        } elseif ($provider->name === 'google') {
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
        $provider = OidcProvider::require($this->config, $flow['provider']);
        $returnTo = self::RETURN_TO[$flow['purpose']] ?? '/';
        if (isset($query['error'])) {
            throw new OidcRedirectException($returnTo, sprintf('Signing in with %s was cancelled.', $provider->label));
        }
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($code === '') {
            throw new OidcRedirectException($returnTo, sprintf('%s did not return a sign-in code.', $provider->label));
        }

        try {
            $claims = $this->claimsFor($provider, $code, $flow['verifier'], $flow['nonce']);
        } catch (ApiException $exception) {
            throw new OidcRedirectException($returnTo, $exception->getMessage());
        }
        $subject = (string) $claims['sub'];

        return match ($flow['purpose']) {
            'link' => $this->link((int) $flow['user_id'], $provider, $subject, $ipAddress),
            'step_up' => $this->stepUp((int) $flow['user_id'], $provider, $claims, $flow['created_at'], $ipAddress),
            'restore' => $this->restore($provider, $subject, $ipAddress),
            'picture' => $this->importPicture((int) $flow['user_id'], $provider, $claims, $ipAddress),
            default => $this->signIn($provider, $subject, $ipAddress),
        };
    }

    /**
     * The provider of a sign-up that is waiting for its username, if any.
     *
     * @return ?array{id:string, label:string}
     */
    public function pendingSignUp(): ?array
    {
        $pending = $this->signUpStash();
        if ($pending === null) {
            return null;
        }
        $provider = OidcProvider::configured($this->config)[$pending['provider']] ?? null;

        return $provider === null ? null : ['id' => $provider->name, 'label' => $provider->label];
    }

    /**
     * Creates the account for a confirmed provider sign-up once its owner has
     * chosen a username. The caller applies registration protection first and
     * signs the new account in.
     */
    public function completeSignUp(string $username, ?string $birthDate, string $ipAddress): AuthenticatedUser
    {
        $pending = $this->signUpStash();
        if ($pending === null) {
            throw new ApiException(400, 'oidc_sign_up_expired', 'This sign-up has expired. Please continue with Google or Twitch again.');
        }
        $user = (new AuthService($this->pdo, $this->config))->registerWithProvider(
            $username,
            $pending['provider'],
            $pending['subject'],
            $ipAddress,
            $birthDate,
        );
        unset($_SESSION['oidc_sign_up']);

        return $user;
    }

    public function cancelSignUp(): void
    {
        unset($_SESSION['oidc_sign_up']);
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
        if (!$this->users->hasPassword($actor->id) && count($this->identities($actor->id)) <= 1) {
            throw new ApiException(
                409,
                'last_sign_in_method',
                'This is your only way to sign in. Set a password or connect another provider first.',
            );
        }
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
            return $this->offerSignUp($provider, $subject);
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

    /**
     * A provider account nobody here uses yet: remember it in this session and
     * let its owner choose a username, if new accounts are being accepted.
     */
    private function offerSignUp(OidcProvider $provider, string $subject): string
    {
        try {
            (new LockdownService($this->pdo))->assertOpen();
        } catch (ApiException $exception) {
            throw new OidcRedirectException('/', $exception->getMessage());
        }
        $settings = $this->pdo->query('SELECT registration_enabled::int FROM system_settings WHERE id = 1');
        if ($settings === false || (int) $settings->fetchColumn() !== 1) {
            throw new OidcRedirectException('/', sprintf(
                'No account here is connected to this %1$s account, and new accounts are not being accepted right now. If you have an account, sign in with your password and connect %1$s on your Account page.',
                $provider->label,
            ));
        }
        $_SESSION['oidc_sign_up'] = ['provider' => $provider->name, 'subject' => $subject, 'created_at' => time()];

        return '/?sign_up=continue';
    }

    /**
     * Confirms a sensitive action for the signed-in account, as a password or
     * passkey would.
     *
     * @param array<string, mixed> $claims
     */
    private function stepUp(int $userId, OidcProvider $provider, array $claims, int $startedAt, string $ipAddress): string
    {
        $returnTo = self::RETURN_TO['step_up'];
        $current = SessionManager::currentUser($this->users);
        if ($current === null || $current->guest || $current->id !== $userId) {
            throw new OidcRedirectException($returnTo, 'Your session changed while confirming. Please try again.');
        }
        try {
            if ((new MfaRepository($this->pdo))->isEnabled($current->id)) {
                throw new ApiException(403, 'passkey_step_up_required', 'This account confirms sensitive actions with a passkey or recovery code.');
            }
            (new RateLimiter($this->pdo, $this->config->rateLimits))->consume('privileged_step_up', $current->id . '|' . $ipAddress);
        } catch (ApiException $exception) {
            throw new OidcRedirectException($returnTo, $exception->getMessage());
        }
        $authTime = $claims['auth_time'] ?? null;
        $fresh = !isset($claims['auth_time'])
            || (is_int($authTime) && $authTime >= $startedAt - self::AUTH_TIME_SKEW_SECONDS);
        if ($this->userIdFor($provider->name, (string) $claims['sub']) !== $current->id || !$fresh) {
            $this->audit->log($current->id, 'auth.privileged_step_up_failed', 'user', (string) $current->id, ['method' => $provider->name], $ipAddress);
            throw new OidcRedirectException($returnTo, $fresh
                ? sprintf('That %s account is not the one connected to your account.', $provider->label)
                : sprintf('%s did not ask you to sign in again. Please try again.', $provider->label));
        }

        $this->touch($provider->name, (string) $claims['sub']);
        $this->audit->log(
            $current->id,
            'auth.privileged_step_up_succeeded',
            'user',
            (string) $current->id,
            ['method' => $provider->name, 'max_age_seconds' => $this->config->privilegedStepUpMaxAgeSeconds],
            $ipAddress,
        );
        SessionManager::establishPrivilegedStepUp($current, $provider->name);

        return $returnTo . '?confirmed=1';
    }

    /**
     * Fetches the profile picture of the signed-in account's connected
     * provider account, for the crop step on the Account page. Every other
     * claim (a name, say) is ignored.
     *
     * @param array<string, mixed> $claims
     */
    private function importPicture(int $userId, OidcProvider $provider, array $claims, string $ipAddress): string
    {
        $returnTo = self::RETURN_TO['picture'];
        $current = SessionManager::currentUser($this->users);
        if ($current === null || $current->guest || $current->id !== $userId) {
            throw new OidcRedirectException($returnTo, 'Your session changed meanwhile. Please try again.');
        }
        if ($this->userIdFor($provider->name, (string) $claims['sub']) !== $current->id) {
            throw new OidcRedirectException($returnTo, sprintf('That %s account is not the one connected to your account.', $provider->label));
        }
        $url = $provider->pictureUrl($claims['picture'] ?? null);
        if ($url === null) {
            throw new OidcRedirectException($returnTo, sprintf('%s has no profile picture to use.', $provider->label));
        }
        try {
            (new AvatarService($this->pdo, $this->config))->stashImport(
                $current->id,
                $this->http->getBytes($url, AvatarService::IMPORT_MAX_BYTES),
            );
        } catch (ApiException $exception) {
            throw new OidcRedirectException($returnTo, sprintf('The %s picture could not be used. %s', $provider->label, $exception->getMessage()));
        }
        $this->audit->log($current->id, 'account.avatar_imported', 'user', (string) $current->id, ['provider' => $provider->name], $ipAddress);

        return '/account.php?picture=ready';
    }

    /** Restores a closing account whose owner proved it through a connected provider. */
    private function restore(OidcProvider $provider, string $subject, string $ipAddress): string
    {
        $returnTo = self::RETURN_TO['restore'];
        $userId = $this->userIdFor($provider->name, $subject);
        if ($userId === null) {
            throw new OidcRedirectException($returnTo, sprintf('No account here is connected to this %s account.', $provider->label));
        }
        $closure = new AccountClosureService($this->pdo, $this->config);
        try {
            $pending = $closure->authenticateRestoreForUser($userId, $ipAddress);
            $this->touch($provider->name, $subject);
            // Multi-factor authentication applies exactly as after a password.
            if ((new MfaService($this->pdo, $this->config))->requiresMfaForLogin($pending)) {
                SessionManager::beginMfaLogin($pending, $ipAddress, $this->config->mfaPendingLoginTtlSeconds, 'restore');
                return $returnTo . '?mfa=continue';
            }
            $user = $closure->completeRestore($pending->id, $ipAddress);
            (new AuthService($this->pdo, $this->config))->completeLogin($user, $ipAddress);
        } catch (ApiException $exception) {
            throw new OidcRedirectException($returnTo, $exception->getMessage());
        }
        SessionManager::login($user);

        return '/';
    }

    /** @return ?array{provider:string, subject:string} */
    private function signUpStash(): ?array
    {
        $pending = $_SESSION['oidc_sign_up'] ?? null;
        if (
            !is_array($pending)
            || !is_string($pending['provider'] ?? null)
            || !is_string($pending['subject'] ?? null)
            || !is_int($pending['created_at'] ?? null)
            || $pending['created_at'] <= time() - self::SIGN_UP_TTL_SECONDS
        ) {
            return null;
        }

        return ['provider' => $pending['provider'], 'subject' => $pending['subject']];
    }

    private function link(int $userId, OidcProvider $provider, string $subject, string $ipAddress): string
    {
        $current = SessionManager::currentUser($this->users);
        if ($current === null || $current->guest || $current->id !== $userId) {
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

    /** @return array<string, mixed> the verified ID-token claims */
    private function claimsFor(OidcProvider $provider, string $code, string $verifier, string $nonce): array
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

        return $claims;
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
