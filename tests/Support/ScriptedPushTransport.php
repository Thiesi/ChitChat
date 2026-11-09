<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use ChitChat\WebPush\PushSendResult;
use ChitChat\WebPush\PushTransport;
use Closure;

final class ScriptedPushTransport implements PushTransport
{
    /** @var list<array{subscription:array<string,mixed>,payload:string,ttl:int,urgency:string}> */
    public array $calls = [];

    /** @param list<PushSendResult> $results */
    public function __construct(public array $results = [], public ?Closure $beforeSend = null)
    {
    }

    public function send(array $subscription, string $payload, int $ttl, string $urgency): PushSendResult
    {
        $this->calls[] = compact('subscription', 'payload', 'ttl', 'urgency');
        if ($this->beforeSend !== null) {
            ($this->beforeSend)();
        }
        return array_shift($this->results) ?? new PushSendResult('sent', 201);
    }
}
