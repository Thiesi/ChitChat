<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use DateTimeImmutable;
use DateTimeZone;

final class PushDeliveryPolicy
{
    public const ATTENTION_LIFETIME_SECONDS = 1800;
    public const OTHER_LIFETIME_SECONDS = 21600;
    public const MAX_ATTEMPTS = 5;
    public const LEASE_SECONDS = 60;
    public const HISTORY_DAYS = 7;

    public static function lifetime(string $kind): int
    {
        return in_array($kind, ['mentioned', 'pinged', 'direct_message_received'], true)
            ? self::ATTENTION_LIFETIME_SECONDS : self::OTHER_LIFETIME_SECONDS;
    }

    public static function urgency(string $kind): string
    {
        return self::lifetime($kind) === self::ATTENTION_LIFETIME_SECONDS ? 'high' : 'normal';
    }

    public static function remainingSeconds(DateTimeImmutable $deadline, DateTimeImmutable $now): int
    {
        return (int) floor((float) $deadline->format('U.u') - (float) $now->format('U.u'));
    }
    public static function retryDelay(int $attempt): int
    {
        $base = min(120, 15 * (2 ** max(0, min(4, $attempt - 1))));
        return min(120, $base + random_int(0, max(1, (int) ($base / 5))));
    }

    public static function retryAfter(string $value, DateTimeImmutable $now): ?int
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        if (ctype_digit($value)) {
            return strlen($value) > 9 ? PHP_INT_MAX : (int) $value;
        }
        // HTTP-date, not PHP's permissive relative-date parser.
        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
        return $date === false ? null : max(0, $date->getTimestamp() - $now->getTimestamp());
    }
}
