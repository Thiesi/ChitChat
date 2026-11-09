<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use InvalidArgumentException;

final readonly class PushDestinationGuard
{
    public function __construct(private PushDnsResolver $resolver, private PushAddressPolicy $policy)
    {
    }

    /** @return array{endpoint:PushEndpoint,addresses:non-empty-list<string>} */
    public function validate(string $url): array
    {
        $endpoint = PushEndpoint::parse($url);
        $addresses = filter_var($endpoint->host, FILTER_VALIDATE_IP) !== false
            ? [$endpoint->host] : $this->resolver->resolve($endpoint->host);
        if (count($addresses) > 64) {
            throw new InvalidArgumentException('Too many push destination addresses.');
        }
        foreach ($addresses as $address) {
            if (!$this->policy->allows($endpoint->origin, $address)) {
                throw new InvalidArgumentException('Push destination is not permitted.');
            }
        }
        return ['endpoint' => $endpoint, 'addresses' => $addresses];
    }
}