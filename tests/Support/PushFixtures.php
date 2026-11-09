<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use Base64Url\Base64Url;
use ChitChat\WebPush\PushAddressPolicy;
use ChitChat\WebPush\PushDestinationGuard;
use ChitChat\WebPush\PushDnsResolver;
use ChitChat\WebPush\PushSubscriptionService;
use Minishlink\WebPush\VAPID;
use PDO;

final class PushFixtures
{
    /** @var array<int,string> */
    private static array $keys = [];

    public static function key(int $generation = 0): string
    {
        return self::$keys[$generation] ??= VAPID::createVapidKeys()['publicKey'];
    }

    public static function auth(int $generation = 0): string
    {
        return Base64Url::encode(substr(hash('sha256', 'fixture-' . $generation, true), 0, 16));
    }

    public static function guard(): PushDestinationGuard
    {
        return new PushDestinationGuard(new class implements PushDnsResolver {
            public function resolve(string $host): array { return ['8.8.8.8']; }
        }, new PushAddressPolicy());
    }

    public static function subscriptions(PDO $pdo): PushSubscriptionService
    {
        return new PushSubscriptionService($pdo, self::guard());
    }
}