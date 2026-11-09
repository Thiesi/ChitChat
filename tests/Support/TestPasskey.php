<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use ChitChat\Auth\Base64Url;
use ChitChat\Auth\MfaRepository;
use OpenSSLAsymmetricKey;
use PDO;
use RuntimeException;

/** Actual ES256 signatures for service and real-HTTP recovery tests, not a verifier mock. */
final readonly class TestPasskey
{
    public function __construct(
        public OpenSSLAsymmetricKey $privateKey,
        public string $credentialId,
        public string $cose,
        public string $userHandle,
        public string $recoveryCode,
    ) {
    }

    public static function create(): self
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$private instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to create the test authenticator.');
        }
        $details = openssl_pkey_get_details($private);
        $ec = is_array($details) ? ($details['ec'] ?? null) : null;
        if (!is_array($ec) || !is_string($ec['x'] ?? null) || !is_string($ec['y'] ?? null)) {
            throw new RuntimeException('Test authenticator has no EC coordinates.');
        }
        $x = str_pad($ec['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $cose = "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
        return new self($private, random_bytes(32), $cose, random_bytes(32),
            implode('-', str_split(strtoupper(bin2hex(random_bytes(12))), 4)));
    }

    public function seed(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare("UPDATE users SET mfa_enabled_at = NOW(), webauthn_user_handle = decode(:handle, 'base64') WHERE id = :id");
        if ($statement === false) {
            throw new RuntimeException('Unable to seed test MFA state.');
        }
        $statement->execute(['handle' => base64_encode($this->userHandle), 'id' => $userId]);
        $repository = new MfaRepository($pdo);
        $repository->insertCredential($userId, $this->credentialId, $this->cose, -7, 0, ['internal'], false, false, 'Test authenticator');
        $repository->replaceRecoveryCodeHashes($userId, [hash('sha256', str_replace('-', '', $this->recoveryCode))]);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function assertion(array $options, int $counter = 1, string $origin = 'https://chat.test', string $rpId = 'chat.test'): array
    {
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'],
            'origin' => $origin, 'crossOrigin' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $authenticator = hash('sha256', $rpId, true) . chr(0x05) . pack('N', $counter);
        $signature = '';
        if (!openssl_sign($authenticator . hash('sha256', $clientData, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the test assertion.');
        }
        return ['id' => Base64Url::encode($this->credentialId), 'rawId' => Base64Url::encode($this->credentialId),
            'type' => 'public-key', 'response' => [
                'clientDataJSON' => Base64Url::encode($clientData),
                'authenticatorData' => Base64Url::encode($authenticator),
                'signature' => Base64Url::encode($signature),
                'userHandle' => Base64Url::encode($this->userHandle),
            ]];
    }
}
