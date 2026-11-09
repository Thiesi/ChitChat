<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

interface PushDnsResolver
{
    /** @return non-empty-list<string> All A and AAAA addresses, or a safe failure. */
    public function resolve(string $host): array;
}