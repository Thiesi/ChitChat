<?php

declare(strict_types=1);

namespace ChitChat\Auth;

/** Server-only committed issuance. Never serialize the private token to HTTP or audit. */
final readonly class IssuedLogin
{
    public function __construct(
        public string $id,
        public int $userId,
        public int $sessionVersion,
        #[\SensitiveParameter] public string $token,
    ) {}
}
