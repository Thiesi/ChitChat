<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use RuntimeException;

final class PushDnsException extends RuntimeException
{
    public function __construct(public readonly bool $retryable)
    {
        parent::__construct('Push provider DNS is unavailable.');
    }
}