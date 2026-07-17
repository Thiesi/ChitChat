<?php

declare(strict_types=1);

namespace ChitChat\Http;

use InvalidArgumentException;

final readonly class RateLimitPolicy
{
    public function __construct(
        public string $name,
        public int $maximumAttempts,
        public int $windowSeconds,
    ) {
        if (preg_match('/\A[a-z0-9_.-]{1,64}\z/D', $this->name) !== 1) {
            throw new InvalidArgumentException('Rate-limit policy name is invalid.');
        }
        if ($this->maximumAttempts < 1) {
            throw new InvalidArgumentException('Rate-limit maximum attempts must be at least 1.');
        }
        if ($this->windowSeconds < 1) {
            throw new InvalidArgumentException('Rate-limit window must be at least 1 second.');
        }
    }

    /** @return array{name:string, maximum_attempts:int, window_seconds:int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'maximum_attempts' => $this->maximumAttempts,
            'window_seconds' => $this->windowSeconds,
        ];
    }
}
