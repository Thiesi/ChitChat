<?php

declare(strict_types=1);
namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;

final class DirectMessageBlockServiceTest extends DatabaseTestCase
{
    public function testUnilateralBlockStopsBothDirectionsAndPreservesHistory(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $messages = new DirectMessageService($this->pdo);
        $blocks = new DirectMessageBlockService($this->pdo);

        $messages->send($alice, $bob->id, 'Before the block');
        self::assertSame(
            ['blocked_by_me' => false, 'messaging_available' => true],
            $blocks->relationship($alice, $bob->id),
        );

        self::assertSame(
            ['blocked_by_me' => true, 'messaging_available' => false],
            $blocks->block($bob, $alice->id),
        );
        self::assertSame(
            ['blocked_by_me' => false, 'messaging_available' => false],
            $blocks->relationship($alice, $bob->id),
        );

        $this->assertUnavailable(static fn () => $messages->send($alice, $bob->id, 'Blocked outbound'));
        $this->assertUnavailable(static fn () => $messages->send($bob, $alice->id, 'Blocked reverse'));
        self::assertSame(['Before the block'], array_column($messages->history($alice, $bob->id), 'body'));

        self::assertSame(
            ['blocked_by_me' => false, 'messaging_available' => true],
            $blocks->unblock($bob, $alice->id),
        );
        self::assertSame('After the unblock', $messages->send($alice, $bob->id, 'After the unblock')['body']);
    }

    public function testBilateralBlocksAreIndependentAndIdempotent(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $alice = $auth->register('Alice', 'a very secure password', '127.0.0.1');
        $bob = $auth->register('Bob', 'another secure password', '127.0.0.2');
        $blocks = new DirectMessageBlockService($this->pdo);

        $blocks->block($alice, $bob->id);
        $blocks->block($alice, $bob->id);
        $blocks->block($bob, $alice->id);

        self::assertSame(
            ['blocked_by_me' => false, 'messaging_available' => false],
            $blocks->unblock($alice, $bob->id),
        );
        self::assertSame(
            ['blocked_by_me' => true, 'messaging_available' => false],
            $blocks->relationship($bob, $alice->id),
        );
        self::assertSame(
            ['blocked_by_me' => false, 'messaging_available' => true],
            $blocks->unblock($bob, $alice->id),
        );
        self::assertSame(
            ['blocked_by_me' => false, 'messaging_available' => true],
            $blocks->unblock($bob, $alice->id),
        );
    }

    public function testSelfBlockingIsRejected(): void
    {
        $alice = (new AuthService($this->pdo, $this->config))
            ->register('Alice', 'a very secure password', '127.0.0.1');

        try {
            (new DirectMessageBlockService($this->pdo))->block($alice, $alice->id);
            self::fail('Expected direct-message self-block rejection.');
        } catch (ApiException $exception) {
            self::assertSame('direct_message_self_forbidden', $exception->errorCode);
        }
    }

    /** @param callable(): mixed $operation */
    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected direct-message availability rejection.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertSame('direct_message_unavailable', $exception->errorCode);
        }
    }
}
