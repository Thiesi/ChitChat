<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Oidc\IdTokenVerifier;
use ChitChat\Auth\Oidc\OidcProvider;
use ChitChat\Http\ApiException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdTokenVerifierTest extends TestCase
{
    private const NOW = 1_790_000_000;
    private const NONCE = 'nonce-from-this-attempt';

    private OpenSSLAsymmetricKey $key;
    /** @var array<string, mixed> */
    private array $jwks;
    private OidcProvider $provider;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => 'key-1',
            'use' => 'sig',
            'n' => self::b64($details['rsa']['n']),
            'e' => self::b64($details['rsa']['e']),
        ]]];
        $this->provider = new OidcProvider(
            name: 'google',
            label: 'Google',
            clientId: 'chitchat-client',
            clientSecret: 'secret',
            issuers: ['https://accounts.google.com', 'accounts.google.com'],
            authorizationEndpoint: 'https://accounts.google.com/o/oauth2/v2/auth',
            tokenEndpoint: 'https://oauth2.googleapis.com/token',
            jwksUri: 'https://www.googleapis.com/oauth2/v3/certs',
        );
    }

    public function testAcceptsAGenuineTokenAndReturnsItsSubject(): void
    {
        $claims = $this->verify($this->token());
        self::assertSame('subject-123', $claims['sub']);
        // Google's shorter issuer form is accepted too.
        self::assertSame('subject-123', $this->verify($this->token(['iss' => 'accounts.google.com']))['sub']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function forgedClaims(): iterable
    {
        yield 'another issuer' => [['iss' => 'https://evil.example'], 'oidc_invalid_token'];
        yield 'another application' => [['aud' => 'someone-else'], 'oidc_invalid_token'];
        yield 'shared audience without azp' => [['aud' => ['chitchat-client', 'other']], 'oidc_invalid_token'];
        yield 'expired' => [['exp' => self::NOW - 3600], 'oidc_invalid_token'];
        yield 'issued long ago' => [['iat' => self::NOW - 7200], 'oidc_invalid_token'];
        yield 'issued in the future' => [['iat' => self::NOW + 3600], 'oidc_invalid_token'];
        yield 'another attempt' => [['nonce' => 'replayed-nonce'], 'oidc_invalid_token'];
        yield 'no subject' => [['sub' => ''], 'oidc_invalid_token'];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('forgedClaims')]
    public function testRefusesTokensThatDoNotBelongToThisSignIn(array $claims, string $code): void
    {
        $this->assertRefused($this->token($claims), $code);
    }

    public function testRefusesTamperedUnsignedAndUnknownKeyTokens(): void
    {
        [$header, $payload, $signature] = explode('.', $this->token());
        $forgedPayload = self::b64((string) json_encode(['sub' => 'attacker'] + $this->claims()));
        $this->assertRefused("{$header}.{$forgedPayload}.{$signature}", 'oidc_invalid_token');

        $none = self::b64('{"alg":"none","kid":"key-1"}');
        $this->assertRefused("{$none}.{$payload}.", 'oidc_invalid_token');

        $this->assertRefused($this->token([], 'rotated-key'), 'oidc_unknown_key');
        $this->assertRefused('not-a-token', 'oidc_invalid_token');
    }

    /** @return array<string, mixed> */
    private function verify(string $token): array
    {
        return (new IdTokenVerifier())->verify($token, $this->provider, self::NONCE, $this->jwks, self::NOW);
    }

    private function assertRefused(string $token, string $code): void
    {
        try {
            $this->verify($token);
            self::fail('Expected the token to be refused.');
        } catch (ApiException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => 'chitchat-client',
            'sub' => 'subject-123',
            'iat' => self::NOW - 5,
            'exp' => self::NOW + 3600,
            'nonce' => self::NONCE,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = [], string $keyId = 'key-1'): string
    {
        $header = self::b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keyId]));
        $payload = self::b64((string) json_encode($overrides + $this->claims()));
        openssl_sign("{$header}.{$payload}", $signature, $this->key, OPENSSL_ALGO_SHA256);

        return "{$header}.{$payload}." . self::b64($signature);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
