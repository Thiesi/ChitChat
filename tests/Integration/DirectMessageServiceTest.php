<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;

final class DirectMessageServiceTest extends DatabaseTestCase
{
    public function testSendHistoryUnreadReadAndTargetedEvents(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $carol = $auth->register('Carol', 'different secure password', '127.0.0.3');
        $service = new DirectMessageService($this->pdo);

        $sent = $service->send($alice, $bob->id, 'Hello Bob');
        self::assertTrue($sent['outgoing']);
        self::assertSame('Hello Bob', $sent['body']);

        $aliceEvents = (new EventRepository($this->pdo))->visibleAfter($alice, 0);
        $bobEvents = (new EventRepository($this->pdo))->visibleAfter($bob, 0);
        $carolEvents = (new EventRepository($this->pdo))->visibleAfter($carol, 0);
        self::assertSame(['direct_message'], array_column(array_map(
            static fn ($event): array => ['type' => $event->type],
            $aliceEvents,
        ), 'type'));
        self::assertSame(['direct_message'], array_column(array_map(
            static fn ($event): array => ['type' => $event->type],
            $bobEvents,
        ), 'type'));
        self::assertSame([], $carolEvents);
        self::assertTrue($aliceEvents[0]->payload['message']['outgoing']);
        self::assertFalse($bobEvents[0]->payload['message']['outgoing']);

        $bobConversations = $service->conversations($bob);
        self::assertSame('Alice', $bobConversations[0]['user']['username']);
        self::assertSame(1, $bobConversations[0]['unread_count']);

        $bobHistory = $service->history($bob, $alice->id);
        self::assertCount(1, $bobHistory);
        self::assertFalse($bobHistory[0]['outgoing']);
        self::assertNull($bobHistory[0]['read_at']);

        self::assertSame(1, $service->markRead($bob, $alice->id));
        self::assertSame(0, $service->conversations($bob)[0]['unread_count']);
        self::assertNotNull($service->history($alice, $bob->id)[0]['read_at']);
    }

    public function testConversationHistoryUsesStableCursorPagination(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $service = new DirectMessageService($this->pdo);

        $first = $service->send($alice, $bob->id, 'First');
        $second = $service->send($bob, $alice->id, 'Second');
        $third = $service->send($alice, $bob->id, 'Third');

        $latest = $service->history($alice, $bob->id, limit: 2);
        self::assertSame([$second['id'], $third['id']], array_column($latest, 'id'));
        $older = $service->history($alice, $bob->id, beforeId: $latest[0]['id'], limit: 2);
        self::assertSame([$first['id']], array_column($older, 'id'));
    }

    public function testSearchExcludesSelfAndSelfMessagingIsRejected(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $auth->register('Alfred', 'another secure password', '127.0.0.2');
        $auth->register('Bob', 'different secure password', '127.0.0.3');
        $service = new DirectMessageService($this->pdo);

        self::assertSame(['Alfred'], array_column($service->searchUsers($alice, 'Al'), 'username'));

        try {
            $service->send($alice, $alice->id, 'No');
            self::fail('Expected direct-message self-send rejection.');
        } catch (ApiException $exception) {
            self::assertSame('direct_message_self_forbidden', $exception->errorCode);
        }
    }
}
