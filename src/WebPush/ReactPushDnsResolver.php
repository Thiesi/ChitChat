<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use Closure;
use React\Dns\Config\Config;
use React\Dns\Model\Message;
use React\Dns\Resolver\Factory;
use React\Dns\Resolver\ResolverInterface;
use React\Dns\RecordNotFoundException;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use Throwable;

/** Synchronous, uncached, push-only lookup with one combined A/AAAA deadline. */
final class ReactPushDnsResolver implements PushDnsResolver
{
    /** @param (Closure(LoopInterface):ResolverInterface)|null $factory Test seam; never a public resolver fallback. */
    public function __construct(private readonly ?Closure $factory = null,
        private readonly string $resolvConf = '/etc/resolv.conf')
    {
    }

    public function resolve(string $host): array
    {
        $loop = new StreamSelectLoop();
        try {
            // Factory honours the system hosts file. Use resolv.conf directly: the Windows
            // convenience loader executes WMIC without a deadline, which is not safe here.
            $resolver = $this->factory !== null ? ($this->factory)($loop)
                : (new Factory())->create(Config::loadResolvConfBlocking($this->resolvConf), $loop);
        } catch (Throwable) {
            throw new PushDnsException(true);
        }
        $addresses = [];
        $failed = false;
        $remaining = 2;
        /** @var list<PromiseInterface<mixed>> $queries */
        $queries = [];
        $timer = $loop->addTimer(3.0, static function () use (&$failed, &$queries, $loop): void {
            $failed = true;
            foreach ($queries as $query) {
                $query->cancel();
            }
            $loop->stop();
        });
        foreach ([Message::TYPE_A, Message::TYPE_AAAA] as $type) {
            $queries[] = $resolver->resolveAll($host, $type)->then(
                static function (array $values) use (&$addresses, &$remaining, $loop): void {
                    foreach ($values as $value) {
                        if (is_string($value)) {
                            $addresses[] = $value;
                        }
                    }
                    if (--$remaining === 0) {
                        $loop->stop();
                    }
                },
                static function (Throwable $error) use (&$failed, &$remaining, $loop): void {
                    // NXDOMAIN/NODATA are absence. A failure in either family is not safe
                    // evidence that the successfully resolved family is the complete set.
                    if (!$error instanceof RecordNotFoundException || !in_array($error->getCode(), [0, 3], true)) {
                        $failed = true;
                    }
                    if (--$remaining === 0) {
                        $loop->stop();
                    }
                },
            );
        }
        // Synchronous promises (hosts file) may already have completed before run().
        if ($remaining > 0) {
            $loop->run();
        }
        $loop->cancelTimer($timer);
        if ($failed) {
            throw new PushDnsException(true);
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === [] || count($addresses) > 64) {
            throw new PushDnsException($addresses !== []);
        }
        return $addresses;
    }
}