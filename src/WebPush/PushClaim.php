<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use DateTimeImmutable;

/** @phpstan-import-type SubscriptionData from PushTransport */
final readonly class PushClaim
{
    /** @param SubscriptionData|null $subscription */
    public function __construct(
        public int $jobId,
        public int $deliveryId,
        public string $token,
        public int $attempts,
        public DateTimeImmutable $expiresAt,
        public PushNotification $notification,
        public ?array $subscription,
        public string $fingerprint,
        public string $eventId,
    ) {
    }
    public function forNotification(PushNotification $notification): self
    {
        return new self($this->jobId, $this->deliveryId, $this->token, $this->attempts,
            $this->expiresAt, $notification, $this->subscription, $this->fingerprint, $this->eventId);
    }
}
