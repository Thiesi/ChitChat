<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

final readonly class PushSendResult
{
    public function __construct(
        public string $outcome,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
    ) {
    }
}
