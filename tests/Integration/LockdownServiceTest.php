<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Admin\LockdownService;
use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Realtime\EventRepository;

final class LockdownServiceTest extends DatabaseTestCase
{
    private const ROOT_PASSWORD = 'a very secure password';
    private const MEMBER_PASSWORD = 'another secure password';

    public function testOnlySuperAdministratorsCanSignInWhileLockedDown(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', self::ROOT_PASSWORD, '127.0.0.1');
        $member = $auth->register('Member', self::MEMBER_PASSWORD, '127.0.0.2');
        $lockdown = new LockdownService($this->pdo);

        try {
            $lockdown->update($member, true, null, false, '127.0.0.2');
            self::fail('Expected a non-Super-Administrator to be refused.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        $status = $lockdown->update($root, true, 'Back at 22:30.', false, '127.0.0.1');
        self::assertTrue($status['enabled']);
        self::assertSame('Back at 22:30.', $status['message']);
        self::assertNotNull($status['since']);

        $this->assertLockedOut(fn () => $auth->login('Member', self::MEMBER_PASSWORD, '127.0.0.2'), 'Back at 22:30.');
        $this->assertLockedOut(fn () => $auth->register('Newcomer', 'a fresh secure password', '127.0.0.3'));
        $this->assertLockedOut(fn () => (new AccountClosureService($this->pdo, $this->config))
            ->authenticateRestore('Member', self::MEMBER_PASSWORD, '127.0.0.2'));
        // The person doing the maintenance can always get back in.
        self::assertSame($root->id, $auth->login('Root', self::ROOT_PASSWORD, '127.0.0.1')->id);

        // Everyone signed in hears about it at once, banner state included.
        $broadcasts = array_values(array_filter(
            self::withoutRoomListSignals((new EventRepository($this->pdo))->visibleAfter($member, 0)),
            static fn ($event): bool => $event->type === 'global_broadcast',
        ));
        self::assertCount(1, $broadcasts);
        self::assertSame(['enabled' => true, 'message' => 'Back at 22:30.'], $broadcasts[0]->payload['lockdown']);

        $lockdown->update($root, false, null, false, '127.0.0.1');
        self::assertSame($member->id, $auth->login('Member', self::MEMBER_PASSWORD, '127.0.0.2')->id);
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action IN ('system.lockdown_enabled', 'system.lockdown_disabled')"));
    }

    public function testSigningEveryoneElseOutSparesSuperAdministrators(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', self::ROOT_PASSWORD, '127.0.0.1');
        $member = $auth->register('Member', self::MEMBER_PASSWORD, '127.0.0.2');
        $before = $this->versions();

        $status = (new LockdownService($this->pdo))->update($root, true, null, true, '127.0.0.1');
        self::assertSame(1, $status['signed_out']);
        self::assertSame(LockdownService::DEFAULT_MESSAGE, $status['message']);

        $after = $this->versions();
        self::assertSame($before[$root->id], $after[$root->id]);
        self::assertSame($before[$member->id] + 1, $after[$member->id]);
        $events = (new EventRepository($this->pdo))->visibleAfter($member, 0);
        $logouts = array_values(array_filter($events, static fn ($event): bool => $event->type === 'forced_logout'));
        self::assertCount(1, $logouts);
        self::assertSame('maintenance_lockdown', $logouts[0]->payload['action']);
        self::assertSame(LockdownService::DEFAULT_MESSAGE, $logouts[0]->payload['reason']);
    }

    private function assertLockedOut(callable $attempt, ?string $message = null): void
    {
        try {
            $attempt();
            self::fail('Expected maintenance lockdown to refuse this.');
        } catch (ApiException $exception) {
            self::assertSame('maintenance_lockdown', $exception->errorCode);
            self::assertSame(503, $exception->status);
            if ($message !== null) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    /** @return array<int, int> */
    private function versions(): array
    {
        $statement = $this->pdo->query('SELECT id, session_version FROM users');
        $versions = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $versions[(int) $row['id']] = (int) $row['session_version'];
        }
        return $versions;
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
