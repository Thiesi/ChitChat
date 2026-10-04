<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Oidc\OidcProvider;
use PHPUnit\Framework\TestCase;

final class OidcProviderTest extends TestCase
{
    public function testPicturesComeOnlyFromTheProvidersOwnImageHosts(): void
    {
        $google = $this->provider('google');
        self::assertSame(
            'https://lh3.googleusercontent.com/a/ACg8ocK=s512-c',
            $google->pictureUrl('https://lh3.googleusercontent.com/a/ACg8ocK=s96-c'),
            'Google pictures are fetched at a size worth cropping.',
        );
        self::assertSame(
            'https://static-cdn.jtvnw.net/jtv_user_pictures/abc-profile_image-300x300.png',
            $this->provider('twitch')->pictureUrl('https://static-cdn.jtvnw.net/jtv_user_pictures/abc-profile_image-300x300.png'),
        );

        foreach ([
            'http://lh3.googleusercontent.com/a/x',
            'https://lh3.googleusercontent.com:8443/a/x',
            'https://user@lh3.googleusercontent.com/a/x',
            'https://googleusercontent.com.evil.example/a/x',
            'https://169.254.169.254/latest/meta-data',
            'https://static-cdn.jtvnw.net/a.png',
            'file:///etc/passwd',
            '',
            null,
            42,
        ] as $claim) {
            self::assertNull($google->pictureUrl($claim), var_export($claim, true));
        }
        self::assertNull($this->provider('twitch')->pictureUrl('https://lh3.googleusercontent.com/a/x'));
    }

    public function testAMockProviderMayServeOnlyItsOwnPictures(): void
    {
        $mock = $this->provider('google', 'http://127.0.0.1:9177');
        self::assertSame('http://127.0.0.1:9177/picture.png', $mock->pictureUrl('http://127.0.0.1:9177/picture.png'));
        self::assertNull($mock->pictureUrl('http://127.0.0.1:9178/picture.png'));
        self::assertNull($mock->pictureUrl('https://lh3.googleusercontent.com/a/x'));
    }

    private function provider(string $name, ?string $override = null): OidcProvider
    {
        return new OidcProvider(
            name: $name,
            label: ucfirst($name),
            clientId: 'client',
            clientSecret: 'secret',
            issuers: ['https://issuer.test'],
            authorizationEndpoint: 'https://issuer.test/authorize',
            tokenEndpoint: 'https://issuer.test/token',
            jwksUri: 'https://issuer.test/keys',
            endpointOverride: $override,
        );
    }
}
