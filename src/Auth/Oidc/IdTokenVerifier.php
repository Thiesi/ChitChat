<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

use ChitChat\Auth\RsaPublicKey;
use ChitChat\Http\ApiException;
use JsonException;

/**
 * Verifies an OpenID Connect ID token: an RS256 signature by one of the
 * provider's published keys, the issuer, this installation as audience,
 * expiry and issue time, and the nonce bound to the sign-in attempt.
 */
final class IdTokenVerifier
{
    private const CLOCK_SKEW_SECONDS = 60;
    private const MAX_AGE_SECONDS = 600;

    /**
     * @param array<string, mixed> $jwks the provider's published key set (external JSON)
     * @return array<string, mixed> the verified claims
     */
    public function verify(string $token, OidcProvider $provider, string $expectedNonce, array $jwks, ?int $now = null): array
    {
        $now ??= time();
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw $this->invalid('The sign-in token is malformed.');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->json($encodedHeader);
        $claims = $this->json($encodedPayload);
        $signature = $this->base64Url($encodedSignature);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw $this->invalid('The sign-in token uses an unexpected algorithm.');
        }
        $key = $this->findKey($jwks, is_string($header['kid'] ?? null) ? $header['kid'] : null);
        if ($key === null) {
            throw new ApiException(401, 'oidc_unknown_key', 'The sign-in token was signed with an unknown key.');
        }
        $pem = RsaPublicKey::pem($this->base64Url((string) $key['n']), $this->base64Url((string) $key['e']));
        if (openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw $this->invalid('The sign-in token signature is invalid.');
        }

        if (!in_array($claims['iss'] ?? null, $provider->issuers, true)) {
            throw $this->invalid('The sign-in token comes from an unexpected issuer.');
        }
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        if (!in_array($provider->clientId, $audiences, true)) {
            throw $this->invalid('The sign-in token is meant for another application.');
        }
        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $provider->clientId) {
            throw $this->invalid('The sign-in token is meant for another application.');
        }
        $expires = $claims['exp'] ?? null;
        $issued = $claims['iat'] ?? null;
        if (!is_int($expires) || $expires < $now - self::CLOCK_SKEW_SECONDS) {
            throw $this->invalid('The sign-in token has expired.');
        }
        if (!is_int($issued) || $issued > $now + self::CLOCK_SKEW_SECONDS || $issued < $now - self::MAX_AGE_SECONDS) {
            throw $this->invalid('The sign-in token was not issued just now.');
        }
        $nonce = $claims['nonce'] ?? null;
        if (!is_string($nonce) || $expectedNonce === '' || !hash_equals($expectedNonce, $nonce)) {
            throw $this->invalid('The sign-in token does not belong to this sign-in attempt.');
        }
        $subject = $claims['sub'] ?? null;
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw $this->invalid('The sign-in token has no usable account identifier.');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $jwks
     * @return ?array<mixed>
     */
    private function findKey(array $jwks, ?string $keyId): ?array
    {
        $keys = $jwks['keys'] ?? [];
        if (!is_array($keys)) {
            return null;
        }
        foreach ($keys as $key) {
            if (
                is_array($key)
                && ($key['kty'] ?? null) === 'RSA'
                && is_string($key['n'] ?? null)
                && is_string($key['e'] ?? null)
                && ($keyId === null || ($key['kid'] ?? null) === $keyId)
                && (($key['use'] ?? 'sig') === 'sig')
            ) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function json(string $encoded): array
    {
        try {
            $decoded = json_decode($this->base64Url($encoded), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->invalid('The sign-in token is malformed.');
        }
        if (!is_array($decoded)) {
            throw $this->invalid('The sign-in token is malformed.');
        }

        return $decoded;
    }

    private function base64Url(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw $this->invalid('The sign-in token is malformed.');
        }

        return $decoded;
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException(401, 'oidc_invalid_token', $message);
    }
}
