<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Base64Url;
use ChitChat\Auth\NativeAndroidApps;
use ChitChat\Auth\WebAuthnProtocol;
use ChitChat\Http\ApiException;
use ChitChat\Tests\Support\TestPasskey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeWebAuthnProtocolTest extends TestCase
{
    private const RP = 'chat.test';
    private const WEB = 'https://chat.test';

    private function origin(): string
    {
        return NativeAndroidApps::origins(NativeAndroidApps::normalize([
            ['package' => 'de.networkxxiii.chitchat', 'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))],
        ]))[0];
    }

    public function testNativeEnrollmentAndSignedAssertionsKeepTheSameRpAndExistingWebCredentials(): void
    {
        $origin = $this->origin();
        $protocol = new WebAuthnProtocol(self::RP, self::WEB, 'ChitChat', nativeOrigins: [$origin]);
        $key = TestPasskey::create();
        $challenge = random_bytes(32);
        $registered = $protocol->verifyRegistration($key->registration(['challenge' => Base64Url::encode($challenge)], $origin),
            $challenge);
        self::assertSame($key->credentialId, $registered['credential_id']);
        self::assertSame($key->cose, $registered['public_key_cose']);
        foreach ([$origin, self::WEB] as $accepted) {
            $challenge = random_bytes(32);
            $result = $protocol->verifyAssertion($key->assertion(['challenge' => Base64Url::encode($challenge)], 1, $accepted),
                $challenge, $key->cose, 0, $key->userHandle);
            self::assertSame(1, $result['sign_count']);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedOrigins(): iterable
    {
        yield 'unlisted-certificate' => ['android:apk-key-hash:' . str_repeat('A', 43)];
        yield 'lookalike-web-origin' => ['https://chat.test.evil.test'];
        yield 'android-trailing-padding' => ['padding'];
        yield 'android-suffix' => ['suffix'];
        yield 'android-prefix-case' => ['case'];
    }

    #[DataProvider('rejectedOrigins')]
    public function testSignedButUnapprovedOriginsAreRejected(string $bad): void
    {
        $allowed = $this->origin();
        $origin = match ($bad) {
            'padding' => $allowed . '=', 'suffix' => $allowed . '.evil',
            'case' => str_replace('android:', 'Android:', $allowed), default => $bad,
        };
        $key = TestPasskey::create();
        $challenge = random_bytes(32);
        $protocol = new WebAuthnProtocol(self::RP, self::WEB, 'CC', nativeOrigins: [$allowed]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('origin');
        $protocol->verifyAssertion($key->assertion(['challenge' => Base64Url::encode($challenge)], 1, $origin),
            $challenge, $key->cose, 0, $key->userHandle);
    }

    public function testNativeTrustDoesNotChangeRpChallengePresenceVerificationOrCounterRequirements(): void
    {
        $origin = $this->origin();
        $protocol = new WebAuthnProtocol(self::RP, self::WEB, 'CC', nativeOrigins: [$origin]);
        $key = TestPasskey::create();
        foreach (['rp', 'challenge', 'up', 'uv', 'counter', 'user'] as $invalid) {
            $challenge = random_bytes(32);
            $credential = $key->assertion(['challenge' => Base64Url::encode($challenge)], 1, $origin,
                $invalid === 'rp' ? 'other.test' : self::RP, match ($invalid) { 'up' => 0x04, 'uv' => 0x01, default => 0x05 });
            try {
                $protocol->verifyAssertion($credential, $invalid === 'challenge' ? random_bytes(32) : $challenge,
                    $key->cose, $invalid === 'counter' ? 1 : 0, $invalid === 'user' ? random_bytes(32) : $key->userHandle);
                self::fail('Expected rejection for ' . $invalid);
            } catch (ApiException $exception) {
                self::assertSame(403, $exception->status);
            }
        }
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('origin');
        $challenge = random_bytes(32);
        (new WebAuthnProtocol(self::RP, self::WEB, 'CC'))->verifyAssertion(
            $key->assertion(['challenge' => Base64Url::encode($challenge)], 1, $origin),
            $challenge, $key->cose, 0, $key->userHandle);
    }

    public function testExtraOriginConfigurationCannotAuthorizeAnotherWebOriginOrNonCanonicalHash(): void
    {
        foreach (['https://other.test', $this->origin() . '=', 'android:apk-key-hash:' . str_repeat('B', 43)] as $invalid) {
            try {
                new WebAuthnProtocol(self::RP, self::WEB, 'CC', nativeOrigins: [$invalid]);
                self::fail('Invalid native origin configuration was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('exact Android', $exception->getMessage());
            }
        }
    }

    public function testNativeOriginStillRejectsCrossOriginAndEmbeddedCeremonies(): void
    {
        $origin = $this->origin();
        $protocol = new WebAuthnProtocol(self::RP, self::WEB, 'CC', nativeOrigins: [$origin]);
        $key = TestPasskey::create();
        foreach ([['crossOrigin' => true], ['topOrigin' => self::WEB], ['type' => 'webauthn.create']] as $changes) {
            $challenge = random_bytes(32);
            $credential = $key->assertion(['challenge' => Base64Url::encode($challenge)], 1, $origin);
            $client = json_decode(Base64Url::decode($credential['response']['clientDataJSON']), true, 8, JSON_THROW_ON_ERROR);
            $credential['response']['clientDataJSON'] = Base64Url::encode(json_encode(array_replace($client, $changes), JSON_THROW_ON_ERROR));
            // Invalid client-data policy is rejected before signature verification.
            try {
                $protocol->verifyAssertion($credential, $challenge, $key->cose, 0, $key->userHandle);
                self::fail('Invalid native client data was accepted.');
            } catch (ApiException $exception) {
                self::assertContains($exception->errorCode, ['webauthn_cross_origin_forbidden', 'webauthn_type_mismatch']);
            }
        }
    }
}
