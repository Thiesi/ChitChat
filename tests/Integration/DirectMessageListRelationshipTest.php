<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageService;

/** The Direct list's per-row relationship and availability (#274). */
final class DirectMessageListRelationshipTest extends DatabaseTestCase
{
    private const PASSWORD = 'Direct-List-Relationship-Aa1!';

    public function testEachRowCarriesBlockStatusRelationshipForAvailablePeers(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $me = $auth->register('Myself', self::PASSWORD, '127.0.0.1');
        $plain = $auth->register('Plain', self::PASSWORD, '127.0.0.2');
        $mine = $auth->register('MineBlocked', self::PASSWORD, '127.0.0.3');
        $theirs = $auth->register('TheyBlocked', self::PASSWORD, '127.0.0.4');
        $both = $auth->register('BothBlocked', self::PASSWORD, '127.0.0.5');
        $banned = $auth->register('Banned', self::PASSWORD, '127.0.0.6');
        $messages = new DirectMessageService($this->pdo);
        foreach ([$plain, $mine, $theirs, $both, $banned] as $peer) {
            $messages->send($me, $peer->id, 'Hello ' . $peer->username);
        }
        $blocks = new DirectMessageBlockService($this->pdo);
        $blocks->block($me, $mine->id);
        $blocks->block($theirs, $me->id);
        $blocks->block($me, $both->id);
        $blocks->block($both, $me->id);
        $this->pdo->exec("INSERT INTO user_bans (user_id, created_by, reason) VALUES ({$banned->id}, {$me->id}, 'Test ban')");

        $rows = $this->rows($me);
        self::assertSame(['blocked_by_me' => false, 'messaging_available' => true], $rows['Plain']['relationship']);
        self::assertSame(['blocked_by_me' => true, 'messaging_available' => false], $rows['MineBlocked']['relationship']);
        self::assertSame(['blocked_by_me' => false, 'messaging_available' => false], $rows['TheyBlocked']['relationship'], 'Their block shows only as unavailability.');
        self::assertSame(['blocked_by_me' => true, 'messaging_available' => false], $rows['BothBlocked']['relationship']);
        self::assertTrue($rows['Banned']['user']['available'], 'A ban is not disclosed.');
        self::assertSame(['blocked_by_me' => false, 'messaging_available' => true], $rows['Banned']['relationship']);

        foreach ([$plain, $mine, $theirs, $both, $banned] as $peer) {
            $row = $rows[$peer->username];
            self::assertSame(['id' => $peer->id, 'username' => $peer->username, 'available' => true], $row['user']);
            self::assertSame($blocks->relationship($me, $peer->id), $row['relationship'], 'Exactly what block-status.php reports.');
        }
        $json = json_encode($this->rows($me), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('blocked_by_other', $json);
        self::assertStringNotContainsString('account_state', $json);
    }

    public function testUnavailablePeersRevealOnlyTheActorsOwnBlock(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $me = $auth->register('Myself', self::PASSWORD, '127.0.0.1');
        $leaving = $auth->register('Leaving', self::PASSWORD, '127.0.0.2');
        $leavingBlockedMe = $auth->register('LeavingBlocker', self::PASSWORD, '127.0.0.3');
        $gone = $auth->register('Gone', self::PASSWORD, '127.0.0.4');
        $messages = new DirectMessageService($this->pdo);
        foreach ([$leaving, $leavingBlockedMe, $gone] as $peer) {
            $messages->send($me, $peer->id, 'Hello ' . $peer->username);
        }
        $blocks = new DirectMessageBlockService($this->pdo);
        $blocks->block($me, $leaving->id);
        $blocks->block($leavingBlockedMe, $me->id);
        $this->pdo->exec("UPDATE users SET account_state = 'closure_pending', closure_requested_at = NOW(), closure_finalizes_at = NOW() + INTERVAL '14 days' WHERE id IN ({$leaving->id}, {$leavingBlockedMe->id})");
        $this->pdo->exec("UPDATE users SET account_state = 'closed', closure_requested_at = NOW() - INTERVAL '15 days', closure_finalizes_at = NOW() - INTERVAL '1 day', closed_at = NOW(), username = 'Closed account #{$gone->id}' WHERE id = {$gone->id}");

        $rows = $this->rows($me);
        self::assertFalse($rows['Leaving']['user']['available']);
        self::assertSame(['blocked_by_me' => true, 'messaging_available' => false], $rows['Leaving']['relationship']);
        self::assertFalse($rows['LeavingBlocker']['user']['available']);
        self::assertSame(['blocked_by_me' => false, 'messaging_available' => false], $rows['LeavingBlocker']['relationship'],
            'Identical to an unblocked unavailable peer: the other side\'s block is not revealed.');
        self::assertSame(['id' => $gone->id, 'username' => "Closed account #{$gone->id}", 'available' => false], $rows["Closed account #{$gone->id}"]['user']);
        self::assertSame(['blocked_by_me' => false, 'messaging_available' => false], $rows["Closed account #{$gone->id}"]['relationship']);
    }

    /** @return array<string, array<string, mixed>> keyed by the peer's username */
    private function rows(AuthenticatedUser $actor): array
    {
        $rows = [];
        foreach ((new DirectMessageService($this->pdo))->conversations($actor) as $row) {
            $rows[$row['user']['username']] = $row;
        }

        return $rows;
    }
}
