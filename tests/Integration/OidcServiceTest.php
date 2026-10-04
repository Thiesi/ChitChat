<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Admin\LockdownService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\Oidc\OidcHttpClient;
use ChitChat\Auth\Oidc\OidcRedirectException;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;

final class OidcServiceTest extends DatabaseTestCase
{
    private FakeProvider $provider;
    private OidcService $oidc;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->provider = new FakeProvider();
        $config = $this->configWith([
            'oidcProviders' => ['google' => [
                'client_id' => FakeProvider::CLIENT_ID,
                'client_secret' => FakeProvider::CLIENT_SECRET,
                'endpoint_override' => FakeProvider::ISSUER,
            ]],
            'oidcRedirectOrigin' => 'https://chat.test',
        ]);
        $this->oidc = new OidcService($this->pdo, $config, $this->provider);
    }

    public function testConnectingAProviderUsesPkceStateAndNonceAndStoresOnlyTheSubject(): void
    {
        [, $member] = $this->users();
        $this->signInAs($member);

        $query = $this->authorize('link', $member);
        self::assertSame('openid', $query['scope']);
        self::assertSame('https://chat.test/api/v1/oidc/callback.php', $query['redirect_uri']);
        self::assertSame('S256', $query['code_challenge_method']);

        $this->provider->subject = 'google-member';
        self::assertSame('/account.php?connected=google', $this->oidc->complete(['state' => $query['state'], 'code' => 'code-1'], '127.0.0.2'));
        self::assertSame(['google'], array_column($this->oidc->identities($member->id), 'provider'));
        $stored = $this->pdo->query('SELECT provider, subject FROM user_identities')?->fetchAll();
        self::assertSame([['provider' => 'google', 'subject' => 'google-member']], $stored);

        // A state works once.
        try {
            $this->oidc->complete(['state' => $query['state'], 'code' => 'code-1'], '127.0.0.2');
            self::fail('Expected a used state to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('oidc_flow_expired', $exception->errorCode);
        }
        self::assertSame(1, $this->provider->keyFetches, 'Signing keys are cached.');
    }

    public function testSignInRefusesUnconnectedAccountsLockdownAndForeignTokens(): void
    {
        [$root, $member] = $this->users();
        $this->signInAs($member);
        $this->provider->subject = 'google-member';
        $this->oidc->complete(['state' => $this->authorize('link', $member)['state'], 'code' => 'c'], '127.0.0.2');
        $_SESSION = [];

        // With registration closed, an unknown provider account cannot sign up.
        $this->pdo->exec('UPDATE system_settings SET registration_enabled = FALSE WHERE id = 1');
        $this->provider->subject = 'google-stranger';
        $this->assertRedirectMessage('login', 'new accounts are not being accepted right now');
        $this->pdo->exec('UPDATE system_settings SET registration_enabled = TRUE WHERE id = 1');

        (new LockdownService($this->pdo))->update($root, true, 'Back soon.', false, '127.0.0.1');
        $this->provider->subject = 'google-member';
        $this->assertRedirectMessage('login', 'Back soon.');
        (new LockdownService($this->pdo))->update($root, false, null, false, '127.0.0.1');

        $this->provider->nonceOverride = 'from-another-attempt';
        $this->assertRedirectMessage('login', 'does not belong to this sign-in attempt');
        $this->provider->nonceOverride = null;

        $this->assertRedirectMessage('login', 'was cancelled', ['error' => 'access_denied']);
    }

    public function testAProviderAccountConnectsToOneChitChatAccountOnly(): void
    {
        [$root, $member] = $this->users();
        $this->provider->subject = 'google-shared';
        $this->signInAs($member);
        $this->oidc->complete(['state' => $this->authorize('link', $member)['state'], 'code' => 'c'], '127.0.0.2');

        $this->signInAs($root);
        try {
            $this->oidc->complete(['state' => $this->authorize('link', $root)['state'], 'code' => 'c'], '127.0.0.1');
            self::fail('Expected the second connection to be refused.');
        } catch (OidcRedirectException $exception) {
            self::assertStringContainsString('already connected to another account', $exception->getMessage());
        }

        $this->oidc->unlink($member, 'google', '127.0.0.2');
        self::assertSame([], $this->oidc->identities($member->id));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'account.identity_unlinked'")?->fetchColumn());
    }

    public function testSigningUpThroughAProviderCreatesAnAccountWithoutAPassword(): void
    {
        $this->users();
        $this->provider->subject = 'google-newcomer';
        $state = $this->authorize('login', null)['state'];
        self::assertSame('/?sign_up=continue', $this->oidc->complete(['state' => $state, 'code' => 'c'], '127.0.0.3'));
        self::assertSame(['id' => 'google', 'label' => 'Google'], $this->oidc->pendingSignUp());

        $user = $this->oidc->completeSignUp('Newcomer', null, '127.0.0.3');
        self::assertNull($this->oidc->pendingSignUp(), 'A pending sign-up is used once.');
        $users = new UserRepository($this->pdo);
        self::assertFalse($users->hasPassword($user->id));
        self::assertSame(['google'], array_column($this->oidc->identities($user->id), 'provider'));
        self::assertSame(
            'google',
            $this->pdo->query("SELECT metadata_json->>'provider' FROM audit_log WHERE action = 'auth.register' AND subject_id = '{$user->id}'")?->fetchColumn(),
        );

        // The random password hash can never be used to sign in.
        try {
            (new AuthService($this->pdo, $this->config))->login('Newcomer', 'any password at all', '127.0.0.3');
            self::fail('A password-less account must not accept a password.');
        } catch (ApiException $exception) {
            self::assertSame('invalid_credentials', $exception->errorCode);
        }

        // The only way in cannot be disconnected until a password exists.
        try {
            $this->oidc->unlink($user, 'google', '127.0.0.3');
            self::fail('Expected the last sign-in method to be kept.');
        } catch (ApiException $exception) {
            self::assertSame('last_sign_in_method', $exception->errorCode);
        }
        (new AuthService($this->pdo, $this->config))->changePassword($user, '', 'a freshly chosen password', '127.0.0.3');
        self::assertTrue($users->hasPassword($user->id));
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.password_set'")?->fetchColumn());
        $user = $users->findAuthenticatedById($user->id);
        self::assertNotNull($user);
        $this->oidc->unlink($user, 'google', '127.0.0.3');

        // A sign-up that was never finished expires; so does a cancelled one.
        $_SESSION['oidc_sign_up'] = ['provider' => 'google', 'subject' => 'late', 'created_at' => time() - 3600];
        self::assertNull($this->oidc->pendingSignUp());
        $_SESSION['oidc_sign_up'] = ['provider' => 'google', 'subject' => 'later', 'created_at' => time()];
        $this->oidc->cancelSignUp();
        $this->expectExceptionObject(new ApiException(400, 'oidc_sign_up_expired', 'This sign-up has expired. Please continue with Google or Twitch again.'));
        $this->oidc->completeSignUp('Latecomer', null, '127.0.0.3');
    }

    public function testStepUpNeedsAFreshSignInWithTheConnectedProviderAccount(): void
    {
        [, $member] = $this->users();
        $this->provider->subject = 'google-member';
        $this->signInAs($member);
        $this->oidc->complete(['state' => $this->authorize('link', $member)['state'], 'code' => 'c'], '127.0.0.2');

        $query = $this->authorize('step_up', $member);
        self::assertSame('0', $query['max_age'], 'Google is asked for a fresh sign-in.');
        $this->provider->authTime = time();
        self::assertSame('/step-up-complete.php?confirmed=1', $this->oidc->complete(['state' => $query['state'], 'code' => 'c'], '127.0.0.2'));
        $status = SessionManager::privilegedStepUpStatus($member, $this->config);
        self::assertTrue($status['active']);
        self::assertSame('google', $status['method']);
        unset($_SESSION['privileged_step_up']);

        // An old provider session does not count.
        $this->provider->authTime = time() - 3600;
        $this->assertStepUpRefused($member, 'did not ask you to sign in again');
        // Nor does somebody else's provider account.
        $this->provider->authTime = time();
        $this->provider->subject = 'google-someone-else';
        $this->assertStepUpRefused($member, 'is not the one connected to your account');
        self::assertFalse(SessionManager::privilegedStepUpStatus($member, $this->config)['active']);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.privileged_step_up_failed'")?->fetchColumn());
    }

    public function testRestoringThroughAProviderNeedsAClosingAccount(): void
    {
        [, $member] = $this->users();
        $this->provider->subject = 'google-member';
        $this->signInAs($member);
        $this->oidc->complete(['state' => $this->authorize('link', $member)['state'], 'code' => 'c'], '127.0.0.2');
        $_SESSION = [];

        $this->assertRedirectMessage('restore', 'not awaiting closure');
        $this->provider->subject = 'google-stranger';
        $this->assertRedirectMessage('restore', 'No account here is connected to this Google account');

        (new AccountClosureService($this->pdo, $this->config))->request($member, '127.0.0.2');
        // A closing account cannot simply sign in; it has to be restored.
        $this->provider->subject = 'google-member';
        $this->assertRedirectMessage('login', 'Restore a closing account');
    }

    private function assertStepUpRefused(AuthenticatedUser $user, string $message): void
    {
        $state = $this->authorize('step_up', $user)['state'];
        try {
            $this->oidc->complete(['state' => $state, 'code' => 'c'], '127.0.0.2');
            self::fail("Expected: {$message}");
        } catch (OidcRedirectException $exception) {
            self::assertSame('/step-up-complete.php', $exception->returnTo);
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * Starts a flow and returns the authorization request's query; the fake
     * provider remembers the nonce and PKCE challenge, as a real one would.
     *
     * @return array<string, string>
     */
    private function authorize(string $purpose, ?AuthenticatedUser $user): array
    {
        parse_str((string) parse_url($this->oidc->begin('google', $purpose, $user), PHP_URL_QUERY), $query);
        $this->provider->nonce = (string) $query['nonce'];
        $this->provider->challenge = (string) $query['code_challenge'];

        /** @var array<string, string> $query */
        return $query;
    }

    /** @param array<string, string> $extra */
    private function assertRedirectMessage(string $purpose, string $message, array $extra = []): void
    {
        $state = $this->authorize($purpose, null)['state'];
        try {
            $this->oidc->complete(['state' => $state, 'code' => 'c', ...$extra], '127.0.0.9');
            self::fail("Expected: {$message}");
        } catch (OidcRedirectException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function signInAs(AuthenticatedUser $user): void
    {
        $_SESSION['auth'] = ['user_id' => $user->id, 'session_version' => $user->sessionVersion, 'authenticated_at' => time()];
    }

    /** @return array{AuthenticatedUser, AuthenticatedUser} */
    private function users(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        return [
            $auth->register('Root', 'a very secure password', '127.0.0.1'),
            $auth->register('Member', 'another secure password', '127.0.0.2'),
        ];
    }
}

/** Issues real RS256 ID tokens and checks the PKCE verifier, like a provider. */
final class FakeProvider implements OidcHttpClient
{
    public const ISSUER = 'https://idp.test';
    public const CLIENT_ID = 'chitchat-client';
    public const CLIENT_SECRET = 'client-secret';

    public string $subject = 'subject';
    public string $nonce = '';
    public string $challenge = '';
    public ?string $nonceOverride = null;
    public ?int $authTime = null;
    public int $keyFetches = 0;
    private \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Unable to generate a test key.');
        }
        $this->key = $key;
    }

    public function postForm(string $url, array $fields): array
    {
        $verifierHash = rtrim(strtr(base64_encode(hash('sha256', $fields['code_verifier'] ?? '', true)), '+/', '-_'), '=');
        if ($url !== self::ISSUER . '/token' || $fields['client_secret'] !== self::CLIENT_SECRET || $verifierHash !== $this->challenge) {
            throw new ApiException(502, 'sign_in_provider_failed', 'The fake provider refused the token request.');
        }
        $now = time();

        $claims = [
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => $this->subject,
            'nonce' => $this->nonceOverride ?? $this->nonce,
            'iat' => $now,
            'exp' => $now + 300,
        ];
        if ($this->authTime !== null) {
            $claims['auth_time'] = $this->authTime;
        }

        return ['id_token' => $this->sign($claims)];
    }

    public function getJson(string $url): array
    {
        $this->keyFetches++;
        $details = openssl_pkey_get_details($this->key);
        if (!is_array($details)) {
            throw new \RuntimeException('Unable to read the test key.');
        }

        return ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'fake-1',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ]]];
    }

    /** @param array<string, mixed> $claims */
    private function sign(array $claims): string
    {
        $header = self::b64((string) json_encode(['alg' => 'RS256', 'kid' => 'fake-1']));
        $payload = self::b64((string) json_encode($claims));
        openssl_sign("{$header}.{$payload}", $signature, $this->key, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}." . self::b64($signature);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
