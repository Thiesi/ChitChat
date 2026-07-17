<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Base64Url;
use ChitChat\Auth\WebAuthnProtocol;
use ChitChat\Http\ApiException;
use OpenSSLAsymmetricKey;
use PHPUnit\Framework\TestCase;

final class WebAuthnProtocolTest extends TestCase
{
    private const RP_ID = 'localhost';
    private const ORIGIN = 'http://localhost:8080';

    public function testVerifiesEs256RegistrationAndAssertion(): void
    {
        [$privateKey, $coseKey] = $this->generateEs256Credential();
        $protocol = new WebAuthnProtocol(self::RP_ID, self::ORIGIN, 'ChitChat');
        $credentialId = random_bytes(32);
        $registrationChallenge = random_bytes(32);
        $registrationClientData = $this->clientData('webauthn.create', $registrationChallenge);
        $registrationAuthenticatorData = hash('sha256', self::RP_ID, true)
            . chr(0x45)
            . pack('N', 0)
            . str_repeat("\0", 16)
            . pack('n', strlen($credentialId))
            . $credentialId
            . $coseKey;
        $attestationObject = $this->cborMap([
            [$this->cborText('fmt'), $this->cborText('none')],
            [$this->cborText('attStmt'), $this->cborMap([])],
            [$this->cborText('authData'), $this->cborBytes($registrationAuthenticatorData)],
        ]);

        $registration = $protocol->verifyRegistration([
            'id' => Base64Url::encode($credentialId),
            'rawId' => Base64Url::encode($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($registrationClientData),
                'attestationObject' => Base64Url::encode($attestationObject),
                'transports' => ['internal', 'hybrid', 'internal', 'unsupported'],
            ],
        ], $registrationChallenge);

        self::assertSame($credentialId, $registration['credential_id']);
        self::assertSame(WebAuthnProtocol::ES256, $registration['algorithm']);
        self::assertSame(0, $registration['sign_count']);
        self::assertSame(['hybrid', 'internal'], $registration['transports']);
        self::assertFalse($registration['backup_eligible']);
        self::assertFalse($registration['backup_state']);

        $assertionChallenge = random_bytes(32);
        $assertionClientData = $this->clientData('webauthn.get', $assertionChallenge);
        $assertionAuthenticatorData = hash('sha256', self::RP_ID, true)
            . chr(0x05)
            . pack('N', 1);
        $signed = $assertionAuthenticatorData . hash('sha256', $assertionClientData, true);
        $signature = '';
        self::assertTrue(openssl_sign($signed, $signature, $privateKey, OPENSSL_ALGO_SHA256));
        $userHandle = random_bytes(32);

        $assertion = $protocol->verifyAssertion([
            'id' => Base64Url::encode($credentialId),
            'rawId' => Base64Url::encode($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($assertionClientData),
                'authenticatorData' => Base64Url::encode($assertionAuthenticatorData),
                'signature' => Base64Url::encode($signature),
                'userHandle' => Base64Url::encode($userHandle),
            ],
        ], $assertionChallenge, $registration['public_key_cose'], 0, $userHandle);

        self::assertSame($credentialId, $assertion['credential_id']);
        self::assertSame(1, $assertion['sign_count']);
    }

    public function testRejectsWrongOriginBeforeCredentialStorage(): void
    {
        [, $coseKey] = $this->generateEs256Credential();
        $protocol = new WebAuthnProtocol(self::RP_ID, self::ORIGIN, 'ChitChat');
        $challenge = random_bytes(32);
        $credentialId = random_bytes(32);
        $clientData = json_encode([
            'type' => 'webauthn.create',
            'challenge' => Base64Url::encode($challenge),
            'origin' => 'http://attacker.invalid',
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $authenticatorData = hash('sha256', self::RP_ID, true)
            . chr(0x45)
            . pack('N', 0)
            . str_repeat("\0", 16)
            . pack('n', strlen($credentialId))
            . $credentialId
            . $coseKey;
        $attestationObject = $this->cborMap([
            [$this->cborText('fmt'), $this->cborText('none')],
            [$this->cborText('attStmt'), $this->cborMap([])],
            [$this->cborText('authData'), $this->cborBytes($authenticatorData)],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('origin');
        $protocol->verifyRegistration([
            'id' => Base64Url::encode($credentialId),
            'rawId' => Base64Url::encode($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'attestationObject' => Base64Url::encode($attestationObject),
            ],
        ], $challenge);
    }

    public function testRejectsNonAdvancingNonzeroSignatureCounter(): void
    {
        [$privateKey, $coseKey] = $this->generateEs256Credential();
        $protocol = new WebAuthnProtocol(self::RP_ID, self::ORIGIN, 'ChitChat');
        $challenge = random_bytes(32);
        $credentialId = random_bytes(32);
        $clientData = $this->clientData('webauthn.get', $challenge);
        $authenticatorData = hash('sha256', self::RP_ID, true) . chr(0x05) . pack('N', 4);
        $signature = '';
        self::assertTrue(openssl_sign(
            $authenticatorData . hash('sha256', $clientData, true),
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256,
        ));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('counter');
        $protocol->verifyAssertion([
            'id' => Base64Url::encode($credentialId),
            'rawId' => Base64Url::encode($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'authenticatorData' => Base64Url::encode($authenticatorData),
                'signature' => Base64Url::encode($signature),
                'userHandle' => null,
            ],
        ], $challenge, $coseKey, 4);
    }

    /** @return array{OpenSSLAsymmetricKey,string} */
    private function generateEs256Credential(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);
        $ec = $details['ec'] ?? null;
        self::assertIsArray($ec);
        $x = $ec['x'] ?? null;
        $y = $ec['y'] ?? null;
        self::assertIsString($x);
        self::assertIsString($y);
        self::assertSame(32, strlen($x));
        self::assertSame(32, strlen($y));

        return [$privateKey, $this->cborMap([
            [$this->cborInteger(1), $this->cborInteger(2)],
            [$this->cborInteger(3), $this->cborInteger(WebAuthnProtocol::ES256)],
            [$this->cborInteger(-1), $this->cborInteger(1)],
            [$this->cborInteger(-2), $this->cborBytes($x)],
            [$this->cborInteger(-3), $this->cborBytes($y)],
        ])];
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => Base64Url::encode($challenge),
            'origin' => self::ORIGIN,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param list<array{string,string}> $pairs */
    private function cborMap(array $pairs): string
    {
        $encoded = $this->cborLength(5, count($pairs));
        foreach ($pairs as [$key, $value]) {
            $encoded .= $key . $value;
        }
        return $encoded;
    }

    private function cborText(string $value): string
    {
        return $this->cborLength(3, strlen($value)) . $value;
    }

    private function cborBytes(string $value): string
    {
        return $this->cborLength(2, strlen($value)) . $value;
    }

    private function cborInteger(int $value): string
    {
        return $value >= 0
            ? $this->cborLength(0, $value)
            : $this->cborLength(1, -1 - $value);
    }

    private function cborLength(int $major, int $value): string
    {
        if ($value < 24) {
            return chr(($major << 5) | $value);
        }
        if ($value <= 0xff) {
            return chr(($major << 5) | 24) . chr($value);
        }
        if ($value <= 0xffff) {
            return chr(($major << 5) | 25) . pack('n', $value);
        }
        return chr(($major << 5) | 26) . pack('N', $value);
    }
}
