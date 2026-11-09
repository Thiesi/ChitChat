<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use SensitiveParameter;

/** Internal storage value. Never serialize it into a response or an audit event. */
final readonly class TotpCredential
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $phase,
        public string $keyId,
        #[SensitiveParameter] public string $ciphertext,
        #[SensitiveParameter] public string $iv,
        #[SensitiveParameter] public string $tag,
        public int $lastAcceptedStep,
        public ?int $pendingSessionVersion,
        public ?string $pendingLoginId,
        public ?string $expiresAt,
        public string $createdAt,
        public ?string $activatedAt,
        public ?string $lastUsedAt,
    ) {}

    /** @return array{phase:string} */
    public function __debugInfo(): array
    {
        return ['phase' => $this->phase];
    }

    public function decrypt(TotpKeyStore $keys): string
    {
        return TotpCipher::decrypt(
            $this->ciphertext, $this->iv, $this->tag, $keys->readKey($this->keyId),
            $this->userId, $this->id, $this->keyId,
        );
    }
}