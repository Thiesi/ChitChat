<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Auth\RegistrationChallenge;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class RegistrationChallengeTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testDisabledProtectionIssuesNoChallengeAndAcceptsSubmissionsWithoutOne(): void
    {
        $challenge = new RegistrationChallenge(0, 0);
        $session = [];

        self::assertFalse($challenge->required());
        self::assertNull($challenge->issue($session, self::NOW));
        $challenge->verify($session, null, null, null, self::NOW);
        $this->addToAssertionCount(1);
    }

    public function testFilledDecoyFieldIsRejectedEvenWhenChallengesAreDisabled(): void
    {
        $session = [];
        self::assertRejected('registration_rejected', static function () use (&$session): void {
            (new RegistrationChallenge(0, 0))->verify($session, 'https://spam.example', null, null, self::NOW);
        });
    }

    public function testSolvedChallengeIsAcceptedOnceAfterTheMinimumFillTime(): void
    {
        $challenge = new RegistrationChallenge(3, 8);
        $session = [];
        $issued = $challenge->issue($session, self::NOW);
        self::assertNotNull($issued);
        self::assertSame(8, $issued['bits']);
        $solution = self::solve($issued['nonce'], $issued['bits']);

        $challenge->verify($session, '', $issued['nonce'], $solution, self::NOW + 5);
        self::assertArrayNotHasKey('registration_challenge', $session);

        self::assertRejected('registration_challenge_missing', static function () use ($challenge, &$session, $issued, $solution): void {
            $challenge->verify($session, '', $issued['nonce'], $solution, self::NOW + 6);
        });
    }

    public function testSubmissionWithoutAChallengeIsRejected(): void
    {
        $session = [];
        self::assertRejected('registration_challenge_missing', static function () use (&$session): void {
            (new RegistrationChallenge(3, 8))->verify($session, null, null, null, self::NOW);
        });
    }

    public function testSubmissionForADifferentChallengeIsRejected(): void
    {
        $challenge = new RegistrationChallenge(3, 8);
        $session = [];
        $challenge->issue($session, self::NOW);

        self::assertRejected('registration_challenge_missing', static function () use ($challenge, &$session): void {
            $challenge->verify($session, null, str_repeat('0', 32), '0', self::NOW + 5);
        });
    }

    public function testSubmissionFasterThanTheMinimumFillTimeIsRejected(): void
    {
        $challenge = new RegistrationChallenge(3, 0);
        $session = [];
        $issued = $challenge->issue($session, self::NOW);
        self::assertNotNull($issued);

        self::assertRejected('registration_too_fast', static function () use ($challenge, &$session, $issued): void {
            $challenge->verify($session, null, $issued['nonce'], '0', self::NOW + 2);
        });
    }

    public function testExpiredChallengeIsRejected(): void
    {
        $challenge = new RegistrationChallenge(3, 0);
        $session = [];
        $issued = $challenge->issue($session, self::NOW);
        self::assertNotNull($issued);

        self::assertRejected('registration_challenge_expired', static function () use ($challenge, &$session, $issued): void {
            $challenge->verify($session, null, $issued['nonce'], '0', self::NOW + 1801);
        });
    }

    public function testWrongOrMalformedProofOfWorkIsRejected(): void
    {
        foreach ([null, '', 'abc', '-1', '1234567890123456'] as $solution) {
            $challenge = new RegistrationChallenge(0, 16);
            $session = [];
            $issued = $challenge->issue($session, self::NOW);
            self::assertNotNull($issued);

            self::assertRejected('registration_challenge_failed', static function () use ($challenge, &$session, $issued, $solution): void {
                $challenge->verify($session, null, $issued['nonce'], $solution, self::NOW);
            });
        }
    }

    public function testDifficultyCountsLeadingZeroBits(): void
    {
        self::assertTrue(RegistrationChallenge::meetsDifficulty("\xFF" . str_repeat("\0", 31), 0));
        self::assertTrue(RegistrationChallenge::meetsDifficulty("\x00\x0F" . str_repeat("\0", 30), 12));
        self::assertFalse(RegistrationChallenge::meetsDifficulty("\x00\x1F" . str_repeat("\0", 30), 12));
        self::assertTrue(RegistrationChallenge::meetsDifficulty("\x00\x00\xFF" . str_repeat("\0", 29), 16));
        self::assertFalse(RegistrationChallenge::meetsDifficulty("\x00\x01" . str_repeat("\0", 30), 16));
    }

    private static function solve(string $nonce, int $bits): string
    {
        for ($counter = 0; ; $counter += 1) {
            if (RegistrationChallenge::meetsDifficulty(hash('sha256', $nonce . ':' . $counter, true), $bits)) {
                return (string) $counter;
            }
        }
    }

    private static function assertRejected(string $errorCode, callable $verify): void
    {
        try {
            $verify();
        } catch (ApiException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
            return;
        }
        self::fail('Expected the registration to be rejected with ' . $errorCode . '.');
    }
}
