<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use ChitChat\Auth\Oidc\OidcHttpClient;
use ChitChat\Http\ApiException;
/** Issues real RS256 ID tokens and checks the PKCE verifier, like a provider. */
final class NativeOidcProvider implements OidcHttpClient
{
    public const ISSUER = 'https://idp.test';
    public const CLIENT_ID = 'chitchat-client';
    public const CLIENT_SECRET = 'client-secret';

    public string $subject = 'subject';
    public string $nonce = '';
    public string $challenge = '';
    public ?string $nonceOverride = null;
    public ?int $authTime = null;
    public ?string $pictureClaim = null;
    public int $keyFetches = 0;
    public int $exchanges = 0;
    public ?\Closure $beforeRequest = null;
    private \OpenSSLAsymmetricKey $key;

    public function __construct(private readonly string $issuer = self::ISSUER, ?string $pem = null)
    {
        $key = $pem === null ? openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) : openssl_pkey_get_private($pem);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Unable to generate a test key.');
        }
        $this->key = $key;
    }

    public function postForm(string $url, array $fields): array
    {
        $this->exchanges++;
        ($this->beforeRequest ?? static function (): void {})();
        $verifierHash = rtrim(strtr(base64_encode(hash('sha256', $fields['code_verifier'] ?? '', true)), '+/', '-_'), '=');
        if ($url !== $this->issuer . '/token' || $fields['client_secret'] !== self::CLIENT_SECRET || $verifierHash !== $this->challenge) {
            throw new ApiException(502, 'sign_in_provider_failed', 'The fake provider refused the token request.');
        }
        $now = time();

        $claims = [
            'iss' => $this->issuer,
            'aud' => self::CLIENT_ID,
            'sub' => $this->subject,
            'nonce' => $this->nonceOverride ?? $this->nonce,
            'iat' => $now,
            'exp' => $now + 300,
        ];
        if ($this->authTime !== null) {
            $claims['auth_time'] = $this->authTime;
        }
        if ($this->pictureClaim !== null) {
            $claims['picture'] = $this->pictureClaim;
            $claims['name'] = 'Ignored Full Name';
        }

        return ['id_token' => $this->sign($claims)];
    }

    public string $picture = '';

    public function getBytes(string $url, int $maxBytes): string
    {
        ($this->beforeRequest ?? static function (): void {})();
        return $this->picture;
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
