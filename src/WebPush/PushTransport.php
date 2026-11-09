<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

/** @phpstan-type SubscriptionData array{id:int,user_id:int,endpoint:string,p256dh_key:string,auth_key:string,session_version?:int,delivery_binding?:?string,generation?:int} */
interface PushTransport
{
    /** @param SubscriptionData $subscription */
    public function send(array $subscription, string $payload, int $ttl, string $urgency): PushSendResult;
}
