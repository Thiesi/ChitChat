<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Config;
use DateTimeImmutable;
use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;
use Psr\Log\NullLogger;
use Psr\Http\Client\ClientInterface;

final class LibraryPushTransport implements PushTransport
{
    private readonly WebPush $webPush;

    public function __construct(Config $config, ?ClientInterface $httpClient = null)
    {
        $client = $httpClient ?? new GuardedPushHttpClient(new PushDestinationGuard(
            new ReactPushDnsResolver(resolvConf: $config->webPushDnsResolvConf), new PushAddressPolicy($config->webPushPrivateProviders)));
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => $config->webPushVapidSubject,
                'publicKey' => $config->webPushVapidPublicKey,
                'privateKey' => $config->webPushVapidPrivateKey,
            ],
        ], client: new VapidHttpClient($client, $config), logger: new NullLogger());
    }

    public function send(array $subscription, string $payload, int $ttl, string $urgency): PushSendResult
    {
        try {
            $target = Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'keys' => ['p256dh' => $subscription['p256dh_key'], 'auth' => $subscription['auth_key']],
                'contentEncoding' => 'aes128gcm',
            ]);
            $report = $this->webPush->sendOneNotification($target, $payload, ['TTL' => $ttl, 'urgency' => $urgency]);
        } catch (Throwable $exception) {
            if ($exception instanceof PushDnsException) {
                return new PushSendResult($exception->retryable ? 'retry' : 'failed', failureReason: $exception->retryable ? 'dns_unavailable' : 'dns_no_addresses');
            }
            if ($exception instanceof InvalidArgumentException) {
                return new PushSendResult('failed', failureReason: 'destination_blocked');
            }
            // Malformed stored keys cannot be repaired by retry. No exception text may expose secrets.
            return new PushSendResult('failed');
        }
        $response = $report->getResponse();
        $status = $response?->getStatusCode();
        if ($status !== null && $status >= 200 && $status < 300) {
            return new PushSendResult('sent', $status);
        }
        if ($status === 404 || $status === 410) {
            return new PushSendResult('expired', $status);
        }
        if ($status === null || $status === 408 || $status === 429 || $status >= 500) {
            return new PushSendResult('retry', $status,
                PushDeliveryPolicy::retryAfter($response?->getHeaderLine('Retry-After') ?? '', new DateTimeImmutable()));
        }
        return new PushSendResult('failed', $status);
    }
}
