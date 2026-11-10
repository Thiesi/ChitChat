<?php

declare(strict_types=1);

namespace ChitChat\Auth\Totp;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

/** Validated server configuration. Key values never appear in diagnostics. */
final readonly class TotpKeyRing
{
    /** @param array<string,string> $keys Raw 32-byte keys. */
    private function __construct(
        #[SensitiveParameter] private array $keys,
        public string $activeKeyId,
        private bool $configurationValid = true,
    ) {
    }

    public static function fromJson(#[SensitiveParameter] string $json, string $activeKeyId): self
    {
        $invalid = static fn (): InvalidArgumentException => new InvalidArgumentException('Invalid TOTP key configuration.');
        if (strlen($json) > 8192) {
            throw $invalid();
        }
        try {
            $decoded = json_decode($json, false, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $invalid();
        }
        if (!$decoded instanceof \stdClass || count(get_object_vars($decoded)) > 8) {
            throw $invalid();
        }
        $keys = [];
        foreach (get_object_vars($decoded) as $id => $encoded) {
            $id = (string) $id;
            if (preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $id) !== 1 || !is_string($encoded)) {
                throw $invalid();
            }
            $key = base64_decode($encoded, true);
            if ($key === false || strlen($key) !== 32 || base64_encode($key) !== $encoded) {
                throw $invalid();
            }
            $keys[$id] = $key;
        }
        if (($keys === [] && $activeKeyId !== '')
            || ($keys !== [] && !array_key_exists($activeKeyId, $keys))) {
            throw $invalid();
        }
        return new self($keys, $activeKeyId);
    }

    /** Configuration loading must not make password or passkey sign-in unavailable.
     * Invalid server intent is retained so it can never select the DB fallback.
     */
    public static function fromConfiguration(#[SensitiveParameter] string $json, string $activeKeyId): self
    {
        try { return self::fromJson($json, $activeKeyId); }
        catch (InvalidArgumentException) { return new self([], '', false); }
    }

    public function valid(): bool
    {
        return $this->configurationValid;
    }

    public function configured(): bool
    {
        return !$this->configurationValid || $this->keys !== [];
    }

    public function key(string $keyId): ?string
    {
        return $this->keys[$keyId] ?? null;
    }

    /** @return array{configured:bool,valid:bool,active_key_id:string} */
    public function __debugInfo(): array
    {
        return ['configured' => $this->configured(), 'valid' => $this->valid(), 'active_key_id' => $this->activeKeyId];
    }
}