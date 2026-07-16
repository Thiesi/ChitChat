<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Moderation\ModerationService;
use ChitChat\Realtime\EventRepository;

final class ForcedLogoutEventTest extends DatabaseTestCase
{
    public function testKickPublishesTargetedForcedLogoutEvent(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', 'a very secure password', '127.0.0.1');
        $target = $auth->register('Target', 'another secure password', '127.0.0.2');

        (new ModerationService($this->pdo))->kick(
            $admin,
            $target->id,
            'Testing disconnect',
            '127.0.0.1',
        );
        $events = (new EventRepository($this->pdo))->visibleAfter($target, 0);

        self::assertCount(1, $events);
        self::assertSame('forced_logout', $events[0]->type);
        self::assertSame('kick', $events[0]->payload['action']);
        self::assertSame('Testing disconnect', $events[0]->payload['reason']);
    }
}
