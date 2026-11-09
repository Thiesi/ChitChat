<?php

declare(strict_types=1);

namespace ChitChat\Auth\Oidc;

/** Server-only verified provider response; never serialize claims into a browser view or audit. */
final readonly class OidcVerification
{
    /** @param array<string,mixed> $claims */
    public function __construct(
        public OidcProvider $provider,
        public string $purpose,
        public ?int $userId,
        public int $startedAt,
        public ?string $nativeTransaction,
        public array $claims,
    ) {
    }
}
