<?php

declare(strict_types=1);

namespace ChitChat\Http;

final readonly class ApiResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public int $status = 200,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function ok(array $payload): self
    {
        return new self($payload);
    }

    /** @param array<string, mixed> $payload */
    public static function created(array $payload): self
    {
        return new self($payload, 201);
    }
}
