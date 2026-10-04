<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\LockdownService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\Oidc\OidcHttpClient;
use ChitChat\Auth\Oidc\OidcRedirectException;
use ChitChat\Auth\Oidc\OidcService;
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

        $this->provider->subject = 'google-stranger';
        $this->assertRedirectMessage('login', 'No account here is connected to this Google account yet');

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

        return ['id_token' => $this->sign([
            'iss' => self::ISSUER,
            'aud' => self::CLIENT_ID,
            'sub' => $this->subject,
            'nonce' => $this->nonceOverride ?? $this->nonce,
            'iat' => $now,
            'exp' => $now + 300,
        ])];
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
