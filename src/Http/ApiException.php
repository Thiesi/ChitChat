<?php

declare(strict_types=1);

namespace ChitChat\Http;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
