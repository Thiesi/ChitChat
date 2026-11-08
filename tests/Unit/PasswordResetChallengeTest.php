<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\PasswordResetChallenge;
use ChitChat\Auth\RegistrationChallenge;
use PHPUnit\Framework\TestCase;

final class PasswordResetChallengeTest extends TestCase
{
    public function testZeroDifficultyStillRequiresOneUseSessionBoundNonce(): void
    {
        $session = [];
        $challenge = new PasswordResetChallenge(0);
        $proof = $challenge->issue($session, 100);
        self::assertSame(0, $proof['bits']);
        self::assertSame(32, strlen($proof['nonce']));
        $other = [];
        self::assertFalse($challenge->consume($other, $proof['nonce'], '0', 101));
        self::assertTrue($challenge->consume($session, $proof['nonce'], '0', 101));
        self::assertFalse($challenge->consume($session, $proof['nonce'], '0', 101));
        self::assertArrayNotHasKey(PasswordResetChallenge::SESSION_KEY, $session);
    }

    public function testWrongMissingExpiredAndFutureProofsAreConsumed(): void
    {
        $challenge = new PasswordResetChallenge(0);
        foreach ([['wrong', '0', 101], [null, '0', 101], ['stored', null, 101],
            ['stored', '0', 400], ['stored', '0', 99]] as [$nonce, $solution, $now]) {
            $session = [];
            $proof = $challenge->issue($session, 100);
            self::assertFalse($challenge->consume($session, $nonce === 'stored' ? $proof['nonce'] : $nonce, $solution, $now));
            self::assertArrayNotHasKey(PasswordResetChallenge::SESSION_KEY, $session);
        }
    }

    public function testRegistrationNonceCannotStandInForAResetNonce(): void
    {
        $session = [];
        $registration = (new RegistrationChallenge(1, 0))->issue($session, 100);
        self::assertIsArray($registration);
        self::assertFalse((new PasswordResetChallenge(0))->consume($session, $registration['nonce'], '0', 101));
        self::assertArrayHasKey('registration_challenge', $session);
    }

    public function testPuzzleUsesTheIssuedDifficultyEvenIfConfigurationChanges(): void
    {
        $session = [];
        $issued = (new PasswordResetChallenge(4))->issue($session, 100);
        $solution = 0;
        while (!RegistrationChallenge::meetsDifficulty(hash('sha256', $issued['nonce'] . ':' . $solution, true), 4)) {
            $solution++;
        }
        self::assertTrue((new PasswordResetChallenge(0))->consume($session, $issued['nonce'], (string) $solution, 100));
        $issued = (new PasswordResetChallenge(4))->issue($session, 100);
        $wrong = 0;
        while (RegistrationChallenge::meetsDifficulty(hash('sha256', $issued['nonce'] . ':' . $wrong, true), 4)) {
            $wrong++;
        }
        self::assertFalse((new PasswordResetChallenge(0))->consume($session, $issued['nonce'], (string) $wrong, 100));
    }
}
