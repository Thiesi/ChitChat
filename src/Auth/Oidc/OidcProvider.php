<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

use ChitChat\Config;
use ChitChat\Http\ApiException;

/**
 * A sign-in provider's OpenID Connect endpoints and this installation's
 * client registration. Endpoints are fixed per provider (no discovery
 * fetch); tests may point them at a local mock through an override that
 * Config allows only in development and test environments.
 */
final readonly class OidcProvider
{
    private const KNOWN = [
        'google' => [
            'label' => 'Google',
            'issuer' => 'https://accounts.google.com',
            'authorization' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
        ],
        'twitch' => [
            'label' => 'Twitch',
            'issuer' => 'https://id.twitch.tv/oauth2',
            'authorization' => 'https://id.twitch.tv/oauth2/authorize',
            'token' => 'https://id.twitch.tv/oauth2/token',
            'jwks' => 'https://id.twitch.tv/oauth2/keys',
        ],
    ];

    /** @param list<string> $issuers */
    public function __construct(
        public string $name,
        public string $label,
        public string $clientId,
        public string $clientSecret,
        public array $issuers,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
    ) {
    }

    /** @return array<string, self> the configured providers, by name */
    public static function configured(Config $config): array
    {
        if (!$config->oidcEnabled()) {
            return [];
        }
        $providers = [];
        foreach ($config->oidcProviders as $name => $settings) {
            $known = self::KNOWN[$name] ?? null;
            if ($known === null) {
                continue;
            }
            $override = $settings['endpoint_override'];
            $issuers = $override !== '' ? [$override] : [$known['issuer']];
            if ($name === 'google' && $override === '') {
                // Google issues tokens with either form of its issuer.
                $issuers[] = 'accounts.google.com';
            }
            $providers[$name] = new self(
                name: $name,
                label: $known['label'],
                clientId: $settings['client_id'],
                clientSecret: $settings['client_secret'],
                issuers: $issuers,
                authorizationEndpoint: $override !== '' ? $override . '/authorize' : $known['authorization'],
                tokenEndpoint: $override !== '' ? $override . '/token' : $known['token'],
                jwksUri: $override !== '' ? $override . '/keys' : $known['jwks'],
            );
        }

        return $providers;
    }

    public static function require(Config $config, string $name): self
    {
        $provider = self::configured($config)[$name] ?? null;
        if ($provider === null) {
            throw new ApiException(404, 'sign_in_provider_unavailable', 'That sign-in provider is not available here.');
        }

        return $provider;
    }

    public static function redirectUri(Config $config): string
    {
        return $config->oidcRedirectOrigin . '/api/v1/oidc/callback.php';
    }
}
