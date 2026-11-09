<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use DateTimeImmutable;

final readonly class PushNotification
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public int $id,
        public int $userId,
        public string $kind,
        public array $context,
        public DateTimeImmutable $createdAt,
        public bool $read,
    ) {
    }
}
