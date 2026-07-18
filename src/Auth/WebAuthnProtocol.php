<?php

declare(strict_types=1);
namespace ChitChat\Auth;

use ChitChat\Http\ApiException;
use JsonException;
use Throwable;

final class WebAuthnProtocol
{
    public const ES256 = -7;
    public const RS256 = -257;

    public function __construct(
        private readonly string $rpId,
        private readonly string $origin,
        private readonly string $rpName,
        private readonly int $timeoutMilliseconds = 300_000,
    ) {
    }

    /**
     * @param list<array{id:string, transports:list<string>}> $excludedCredentials
     * @return array<string, mixed>
     */
    public function registrationOptions(
        string $challenge,
        string $userHandle,
        string $username,
        array $excludedCredentials,
    ): array {
        return [
            'challenge' => Base64Url::encode($challenge),
            'rp' => ['id' => $this->rpId, 'name' => $this->rpName],
            'user' => [
                'id' => Base64Url::encode($userHandle),
                'name' => $username,
                'displayName' => $username,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ES256],
                ['type' => 'public-key', 'alg' => self::RS256],
            ],
            'timeout' => $this->timeoutMilliseconds,
            'excludeCredentials' => array_map(
                static fn (array $credential): array => [
                    'type' => 'public-key',
                    'id' => $credential['id'],
                    'transports' => $credential['transports'],
                ],
                $excludedCredentials,
            ),
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'required',
            ],
            'attestation' => 'none',
        ];
    }

    /**
     * @param list<array{id:string, transports:list<string>}> $allowedCredentials
     * @return array<string, mixed>
     */
    public function assertionOptions(string $challenge, array $allowedCredentials): array
    {
        return [
            'challenge' => Base64Url::encode($challenge),
            'timeout' => $this->timeoutMilliseconds,
            'rpId' => $this->rpId,
            'allowCredentials' => array_map(
                static fn (array $credential): array => [
                    'type' => 'public-key',
                    'id' => $credential['id'],
                    'transports' => $credential['transports'],
                ],
                $allowedCredentials,
            ),
            'userVerification' => 'required',
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{credential_id:string,public_key_cose:string,algorithm:int,sign_count:int,backup_eligible:bool,backup_state:bool,transports:list<string>}
     */
    public function verifyRegistration(array $credential, string $expectedChallenge): array
    {
        $this->requirePublicKeyType($credential);
        $response = $this->object($credential, 'response');
        $rawId = Base64Url::decode($this->string($credential, 'rawId'), 'rawId');
        $clientDataJson = Base64Url::decode($this->string($response, 'clientDataJSON'), 'clientDataJSON');
        $this->verifyClientData($clientDataJson, 'webauthn.create', $expectedChallenge);
        $attestationBytes = Base64Url::decode($this->string($response, 'attestationObject'), 'attestationObject');

        try {
            $attestation = CborDecoder::decode($attestationBytes);
        } catch (Throwable) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'The attestation object is invalid.');
        }
        if (!is_array($attestation)) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'The attestation object must be a CBOR map.');
        }
        if (($attestation['fmt'] ?? null) !== 'none') {
            throw new ApiException(400, 'unsupported_attestation', 'Only privacy-preserving none attestation is accepted.');
        }
        $attestationStatement = $attestation['attStmt'] ?? null;
        if (!is_array($attestationStatement) || $attestationStatement !== []) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'None attestation must not contain an attestation statement.');
        }
        $authenticatorData = $attestation['authData'] ?? null;
        if (!is_string($authenticatorData)) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'Attestation authenticator data is missing.');
        }

        $parsed = $this->parseAuthenticatorData($authenticatorData, true);
        if (!hash_equals($rawId, $parsed['credential_id'])) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'The credential identifier does not match the attested credential.');
        }
        $key = $this->parseCoseKey($parsed['public_key_cose']);

        return [
            'credential_id' => $rawId,
            'public_key_cose' => $parsed['public_key_cose'],
            'algorithm' => $key['algorithm'],
            'sign_count' => $parsed['sign_count'],
            'backup_eligible' => $parsed['backup_eligible'],
            'backup_state' => $parsed['backup_state'],
            'transports' => $this->transports($response['transports'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{credential_id:string,sign_count:int,backup_eligible:bool,backup_state:bool}
     */
    public function verifyAssertion(
        array $credential,
        string $expectedChallenge,
        string $publicKeyCose,
        int $storedSignCount,
        ?string $expectedUserHandle = null,
    ): array {
        $this->requirePublicKeyType($credential);
        $response = $this->object($credential, 'response');
        $rawId = Base64Url::decode($this->string($credential, 'rawId'), 'rawId');
        $clientDataJson = Base64Url::decode($this->string($response, 'clientDataJSON'), 'clientDataJSON');
        $this->verifyClientData($clientDataJson, 'webauthn.get', $expectedChallenge);
        $authenticatorData = Base64Url::decode($this->string($response, 'authenticatorData'), 'authenticatorData');
        $signature = Base64Url::decode($this->string($response, 'signature'), 'signature');
        $parsed = $this->parseAuthenticatorData($authenticatorData, false);

        $encodedUserHandle = $response['userHandle'] ?? null;
        if ($expectedUserHandle !== null && $encodedUserHandle !== null) {
            if (!is_string($encodedUserHandle)) {
                throw new ApiException(400, 'invalid_webauthn_payload', 'userHandle must be base64url data or null.');
            }
            $userHandle = Base64Url::decode($encodedUserHandle, 'userHandle');
            if (!hash_equals($expectedUserHandle, $userHandle)) {
                throw new ApiException(403, 'webauthn_user_mismatch', 'The passkey belongs to a different account.');
            }
        }

        $key = $this->parseCoseKey($publicKeyCose);
        $signed = $authenticatorData . hash('sha256', $clientDataJson, true);
        $verified = openssl_verify($signed, $signature, $key['pem'], OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            throw new ApiException(403, 'invalid_passkey_assertion', 'The passkey assertion signature is invalid.');
        }

        $newSignCount = $parsed['sign_count'];
        if ($storedSignCount !== 0 && $newSignCount !== 0 && $newSignCount <= $storedSignCount) {
            throw new ApiException(403, 'passkey_counter_reuse', 'The passkey counter did not advance. The credential may have been cloned or restored unexpectedly.');
        }

        return [
            'credential_id' => $rawId,
            'sign_count' => $newSignCount,
            'backup_eligible' => $parsed['backup_eligible'],
            'backup_state' => $parsed['backup_state'],
        ];
    }

    /** @return array<string, mixed> */
    private function verifyClientData(string $json, string $expectedType, string $expectedChallenge): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'clientDataJSON is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'clientDataJSON must contain an object.');
        }
        if (($decoded['type'] ?? null) !== $expectedType) {
            throw new ApiException(403, 'webauthn_type_mismatch', 'The WebAuthn ceremony type is invalid.');
        }
        $challenge = $decoded['challenge'] ?? null;
        if (!is_string($challenge) || !hash_equals($expectedChallenge, Base64Url::decode($challenge, 'challenge'))) {
            throw new ApiException(403, 'webauthn_challenge_mismatch', 'The WebAuthn challenge is invalid or expired.');
        }
        if (($decoded['origin'] ?? null) !== $this->origin) {
            throw new ApiException(403, 'webauthn_origin_mismatch', 'The WebAuthn origin does not match this installation.');
        }
        if (($decoded['crossOrigin'] ?? false) !== false || array_key_exists('topOrigin', $decoded)) {
            throw new ApiException(403, 'webauthn_cross_origin_forbidden', 'Cross-origin WebAuthn ceremonies are not accepted.');
        }

        return $decoded;
    }

    /** @return array{sign_count:int,backup_eligible:bool,backup_state:bool,credential_id:string,public_key_cose:string} */
    private function parseAuthenticatorData(string $data, bool $registration): array
    {
        if (strlen($data) < 37) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'Authenticator data is truncated.');
        }
        if (!hash_equals(hash('sha256', $this->rpId, true), substr($data, 0, 32))) {
            throw new ApiException(403, 'webauthn_rp_mismatch', 'The passkey relying-party identifier is invalid.');
        }
        $flags = ord($data[32]);
        if (($flags & 0x01) === 0) {
            throw new ApiException(403, 'webauthn_user_presence_required', 'The authenticator did not confirm user presence.');
        }
        if (($flags & 0x04) === 0) {
            throw new ApiException(403, 'webauthn_user_verification_required', 'The authenticator did not verify the user.');
        }
        $backupEligible = ($flags & 0x08) !== 0;
        $backupState = ($flags & 0x10) !== 0;
        if ($backupState && !$backupEligible) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'Authenticator backup flags are inconsistent.');
        }
        $hasAttestedData = ($flags & 0x40) !== 0;
        if ($registration !== $hasAttestedData) {
            throw new ApiException(400, 'invalid_webauthn_payload', $registration
                ? 'Registration authenticator data does not contain a credential.'
                : 'Assertion authenticator data unexpectedly contains an attested credential.');
        }
        $signCount = $this->unsignedBigEndian(substr($data, 33, 4));
        $offset = 37;
        $credentialId = '';
        $publicKeyCose = '';

        if ($registration) {
            if (strlen($data) < $offset + 18) {
                throw new ApiException(400, 'invalid_webauthn_payload', 'Attested credential data is truncated.');
            }
            $offset += 16;
            $credentialLength = $this->unsignedBigEndian(substr($data, $offset, 2));
            $offset += 2;
            if ($credentialLength < 1 || strlen($data) < $offset + $credentialLength) {
                throw new ApiException(400, 'invalid_webauthn_payload', 'The attested credential identifier is invalid.');
            }
            $credentialId = substr($data, $offset, $credentialLength);
            $offset += $credentialLength;
            $keyStart = $offset;
            try {
                CborDecoder::decodeAt($data, $offset);
            } catch (Throwable) {
                throw new ApiException(400, 'invalid_webauthn_payload', 'The attested credential public key is invalid.');
            }
            $publicKeyCose = substr($data, $keyStart, $offset - $keyStart);
        }

        if (($flags & 0x80) !== 0) {
            try {
                CborDecoder::decodeAt($data, $offset);
            } catch (Throwable) {
                throw new ApiException(400, 'invalid_webauthn_payload', 'Authenticator extension data is invalid.');
            }
        }
        if ($offset !== strlen($data)) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'Authenticator data contains trailing bytes.');
        }

        return [
            'sign_count' => $signCount,
            'backup_eligible' => $backupEligible,
            'backup_state' => $backupState,
            'credential_id' => $credentialId,
            'public_key_cose' => $publicKeyCose,
        ];
    }

    /** @return array{algorithm:int,pem:string} */
    private function parseCoseKey(string $encoded): array
    {
        try {
            $key = CborDecoder::decode($encoded);
        } catch (Throwable) {
            throw new ApiException(400, 'invalid_webauthn_key', 'The stored passkey public key is invalid.');
        }
        if (!is_array($key)) {
            throw new ApiException(400, 'invalid_webauthn_key', 'The passkey public key must be a COSE map.');
        }
        $algorithm = $key[3] ?? null;
        $keyType = $key[1] ?? null;
        if ($algorithm === self::ES256 && $keyType === 2) {
            if (($key[-1] ?? null) !== 1 || !is_string($key[-2] ?? null) || !is_string($key[-3] ?? null)) {
                throw new ApiException(400, 'invalid_webauthn_key', 'The ES256 passkey public key is malformed.');
            }
            $x = $key[-2];
            $y = $key[-3];
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                throw new ApiException(400, 'invalid_webauthn_key', 'The ES256 coordinates must be 32 bytes.');
            }
            $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
            if ($prefix === false) {
                throw new ApiException(500, 'webauthn_unavailable', 'Unable to construct the ES256 public key.');
            }
            return ['algorithm' => self::ES256, 'pem' => $this->pem($prefix . "\x04" . $x . $y)];
        }
        if ($algorithm === self::RS256 && $keyType === 3) {
            $modulus = $key[-1] ?? null;
            $exponent = $key[-2] ?? null;
            if (!is_string($modulus) || !is_string($exponent) || $modulus === '' || $exponent === '') {
                throw new ApiException(400, 'invalid_webauthn_key', 'The RS256 passkey public key is malformed.');
            }
            $rsa = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
            $algorithmIdentifier = hex2bin('300d06092a864886f70d0101010500');
            if ($algorithmIdentifier === false) {
                throw new ApiException(500, 'webauthn_unavailable', 'Unable to construct the RS256 public key.');
            }
            $der = $this->derSequence($algorithmIdentifier . $this->derBitString($rsa));
            return ['algorithm' => self::RS256, 'pem' => $this->pem($der)];
        }

        throw new ApiException(400, 'unsupported_passkey_algorithm', 'Only ES256 and RS256 passkeys are supported.');
    }

    private function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $value): string
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derBitString(string $value): string
    {
        $value = "\x00" . $value;
        return "\x03" . $this->derLength(strlen($value)) . $value;
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");
        if ($value === '') {
            $value = "\x00";
        } elseif ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }
        return chr(0x80 | strlen($encoded)) . $encoded;
    }

    private function unsignedBigEndian(string $bytes): int
    {
        $value = 0;
        for ($index = 0; $index < strlen($bytes); $index++) {
            $value = ($value * 256) + ord($bytes[$index]);
        }
        return $value;
    }

    /** @param array<string, mixed> $credential */
    private function requirePublicKeyType(array $credential): void
    {
        if (($credential['type'] ?? null) !== 'public-key') {
            throw new ApiException(400, 'invalid_webauthn_payload', 'The credential type must be public-key.');
        }
        $id = $this->string($credential, 'id');
        $rawId = $this->string($credential, 'rawId');
        if (!hash_equals(Base64Url::decode($id, 'id'), Base64Url::decode($rawId, 'rawId'))) {
            throw new ApiException(400, 'invalid_webauthn_payload', 'id and rawId identify different credentials.');
        }
    }

    /** @param array<string, mixed> $object */
    private function string(array $object, string $key): string
    {
        $value = $object[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ApiException(400, 'invalid_webauthn_payload', $key . ' must be a nonempty string.');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function object(array $object, string $key): array
    {
        $value = $object[$key] ?? null;
        if (!is_array($value)) {
            throw new ApiException(400, 'invalid_webauthn_payload', $key . ' must be an object.');
        }
        return $value;
    }

    /** @return list<string> */
    private function transports(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $allowed = ['ble', 'cable', 'hybrid', 'internal', 'nfc', 'smart-card', 'usb'];
        $result = [];
        foreach ($value as $transport) {
            if (is_string($transport) && in_array($transport, $allowed, true)) {
                $result[] = $transport;
            }
        }
        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }
}
