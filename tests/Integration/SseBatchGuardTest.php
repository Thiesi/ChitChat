<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Database;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\SseBatchGuard;

final class SseBatchGuardTest extends DatabaseTestCase
{
    public function testRevocationBetweenTheOriginalCheckAndFetchDropsThePostRevocationBatch(): void
    {
        $_SESSION = [];
        $user = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $logins = new LoginSessionRepository($this->pdo);
        $revoked = $logins->issue($user);
        $surviving = $logins->issue($user);
        $revokedProof = ['id' => $revoked->id, 'hash' => hash('sha256', $revoked->token)];
        $survivingProof = ['id' => $surviving->id, 'hash' => hash('sha256', $surviving->token)];
        self::assertNull($logins->invalidReason($user->id, $user->sessionVersion, $revokedProof['id'], $revokedProof['hash']));

        // A separate committed connection controls the exact old check/fetch gap.
        $control = Database::connect($this->config);
        self::assertSame(1, (new LoginSessionRepository($control))->revoke(
            $user, $survivingProof['id'], $survivingProof['hash'], $revokedProof['id'], '127.0.0.1'));
        $event = (new EventRepository($control))->publish('global_broadcast',
            ['message' => 'after-login-revocation'], targetUserId: $user->id);
        $batch = (new EventRepository($this->pdo))->visibleAfter($user, 0);
        self::assertContains($event->id, array_map(static fn ($item): int => $item->id, $batch),
            'The post-revocation event is visible to an ordinary fetch, reproducing the old gap.');
        $guard = new SseBatchGuard($this->pdo);
        self::assertSame(['events' => [], 'reason' => 'session_revoked'], $guard->filter($user, $revokedProof, $batch));
        self::assertSame(['events' => $batch, 'reason' => null], $guard->filter($user, $survivingProof, $batch));
        self::assertNull($logins->invalidReason($user->id, $user->sessionVersion, $survivingProof['id'], $survivingProof['hash']));
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM realtime_events WHERE event_type = 'forced_logout'")->fetchColumn());
    }

    public function testRevocationAfterFetchStillDiscardsTheAlreadyFetchedBatch(): void
    {
        $_SESSION = [];
        $user = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $logins = new LoginSessionRepository($this->pdo);
        $revoked = $logins->issue($user);
        $surviving = $logins->issue($user);
        $proof = ['id' => $revoked->id, 'hash' => hash('sha256', $revoked->token)];
        (new EventRepository($this->pdo))->publish('global_broadcast', ['message' => 'already-fetched'], targetUserId: $user->id);
        $batch = (new EventRepository($this->pdo))->visibleAfter($user, 0);
        self::assertNotEmpty($batch);
        $control = Database::connect($this->config);
        (new LoginSessionRepository($control))->revoke($user, $surviving->id,
            hash('sha256', $surviving->token), $revoked->id, '127.0.0.1');
        self::assertSame(['events' => [], 'reason' => 'session_revoked'],
            (new SseBatchGuard($this->pdo))->filter($user, $proof, $batch));
    }
}