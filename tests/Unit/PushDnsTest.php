<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\WebPush\PushDnsException;
use ChitChat\WebPush\ReactPushDnsResolver;
use React\Dns\Model\Message;
use React\Dns\RecordNotFoundException;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use function React\Promise\resolve;
use function React\Promise\reject;

final class PushDnsTest extends TestCase
{
    public function testBothAddressFamiliesAreCollectedWithoutCrossCallCaching(): void
    {
        $types = [];
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->method('resolveAll')->willReturnCallback(static function (string $host, int $type) use (&$types): PromiseInterface {
            $types[] = $type;
            return resolve($type === Message::TYPE_A ? ['8.8.8.8'] : ['2606:4700:4700::1111']);
        });
        $dns = new ReactPushDnsResolver(static fn (LoopInterface $loop): ResolverInterface => $resolver);
        for ($i = 0; $i < 2; ++$i) self::assertSame(['8.8.8.8', '2606:4700:4700::1111'], $dns->resolve('push.example'));
        self::assertSame([Message::TYPE_A, Message::TYPE_AAAA, Message::TYPE_A, Message::TYPE_AAAA], $types);
    }

    public function testMissingOneFamilyIsValidButAnyServfailIsRetryable(): void
    {
        foreach ([0, 3, 2] as $code) {
            $resolver = $this->createMock(ResolverInterface::class);
            $resolver->method('resolveAll')->willReturnCallback(static fn (string $host, int $type): PromiseInterface =>
                $type === Message::TYPE_A ? resolve(['8.8.8.8']) : reject(new RecordNotFoundException('safe fixture', $code)));
            $dns = new ReactPushDnsResolver(static fn (LoopInterface $loop): ResolverInterface => $resolver);
            if ($code !== 2) {
                self::assertSame(['8.8.8.8'], $dns->resolve('push.example'));
            } else {
                try { $dns->resolve('push.example'); self::fail('SERVFAIL was ignored.'); }
                catch (PushDnsException $exception) { self::assertTrue($exception->retryable); }
            }
        }
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->method('resolveAll')->willReturnCallback(static fn (): PromiseInterface => reject(new RecordNotFoundException('safe fixture', 3)));
        try {
            (new ReactPushDnsResolver(static fn (LoopInterface $loop): ResolverInterface => $resolver))->resolve('push.example');
            self::fail('NXDOMAIN was accepted.');
        } catch (PushDnsException $exception) {
            self::assertFalse($exception->retryable);
        }
    }

    public function testCombinedDeadlineCancelsBothPendingQueries(): void
    {
        $cancelled = 0;
        $resolver = $this->createMock(ResolverInterface::class);
        $resolver->method('resolveAll')->willReturnCallback(static function () use (&$cancelled): PromiseInterface {
            $deferred = new Deferred(static function () use (&$cancelled): void {
                ++$cancelled;
                throw new RuntimeException('safe fixture cancellation');
            });
            return $deferred->promise();
        });
        $start = hrtime(true);
        try {
            (new ReactPushDnsResolver(static fn (LoopInterface $loop): ResolverInterface => $resolver))->resolve('push.example');
            self::fail('Deadline was ignored.');
        } catch (PushDnsException $exception) {
            self::assertTrue($exception->retryable);
            self::assertSame(2, $cancelled);
            self::assertGreaterThan(2.8, (hrtime(true) - $start) / 1e9);
            self::assertLessThan(4.5, (hrtime(true) - $start) / 1e9);
        }
    }
}