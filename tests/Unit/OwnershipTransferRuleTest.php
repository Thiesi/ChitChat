<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\Room\OwnershipTransferRule;
use PHPUnit\Framework\TestCase;

/** The one rule behind the transfer and its flags (#273, #302): its refusals, in order. */
final class OwnershipTransferRuleTest extends TestCase
{
    private const ACTOR = 1;
    private const OWNER = 2;
    private const TARGET = 3;

    public function testTheOwnerMayHandTheRoomToAnEligibleMember(): void
    {
        self::assertNull($this->refusal());
    }

    public function testOnlyTheOwnerOrAManagerOfEveryRoomMayTransfer(): void
    {
        self::assertSame([403, 'forbidden'], $this->refusal(ownerId: self::OWNER));
        self::assertNull($this->refusal(ownerId: self::OWNER, managesAnyRoom: true, actorRank: 3));
        self::assertSame([403, 'forbidden'], $this->refusal(ownerId: null), 'A room without an owner is for managers only.');
    }

    public function testRefusalsComeInTheEndpointsOrder(): void
    {
        // Not allowed at all comes before anything about the target.
        self::assertSame([403, 'forbidden'], $this->refusal(ownerId: self::OWNER, targetRole: 'owner', eligible: false));
        self::assertSame([409, 'already_owner'], $this->refusal(targetRole: 'owner', eligible: false));
        self::assertSame([404, 'membership_not_found'], $this->refusal(targetRole: null, eligible: false));
        self::assertSame([409, 'transfer_target_not_eligible'], $this->refusal(eligible: false, ownerId: self::OWNER, managesAnyRoom: true, ownerRank: 5));
    }

    public function testAnActiveOwnerIsReplacedOnlyByAnEqualOrHigherGlobalRank(): void
    {
        $base = ['ownerId' => self::OWNER, 'managesAnyRoom' => true, 'actorRank' => 3];
        self::assertSame([403, 'forbidden'], $this->refusal(...[...$base, 'ownerRank' => 4]));
        self::assertNull($this->refusal(...[...$base, 'ownerRank' => 3]), 'An equal rank may.');
        self::assertNull($this->refusal(...[...$base, 'ownerRank' => 4, 'ownerActive' => false]), 'A closing or closed owner can be replaced by any manager.');
    }

    public function testATakeoverIsForManagersAndChecksTheAgeLast(): void
    {
        $takeover = ['targetId' => self::ACTOR, 'targetRole' => null, 'ownerId' => self::OWNER, 'managesAnyRoom' => true, 'actorRank' => 4, 'ownerRank' => 4];
        $tooYoung = new ApiException(403, 'minimum_age_not_met', 'Too young.');
        self::assertNull($this->refusal(...$takeover));
        self::assertSame([403, 'minimum_age_not_met'], $this->refusal(...[...$takeover, 'age' => $tooYoung]));
        self::assertSame([403, 'forbidden'], $this->refusal(...[...$takeover, 'ownerRank' => 5, 'age' => $tooYoung]), 'Rank before age.');
        self::assertSame([404, 'membership_not_found'], $this->refusal(...[...$takeover, 'managesAnyRoom' => false, 'ownerId' => self::ACTOR]), 'An owner can only hand the room to members.');
    }

    public function testTheAgeIsOnlyAskedForATakeover(): void
    {
        $asked = false;
        OwnershipTransferRule::refusal(self::ACTOR, false, 0, self::TARGET, 'member', true, self::ACTOR, true, 0, static function () use (&$asked): ?ApiException {
            $asked = true;

            return null;
        });
        self::assertFalse($asked);
    }

    /** @return ?array{int, string} */
    private function refusal(
        int $targetId = self::TARGET,
        ?string $targetRole = 'member',
        bool $eligible = true,
        ?int $ownerId = self::ACTOR,
        bool $ownerActive = true,
        int $ownerRank = 0,
        bool $managesAnyRoom = false,
        int $actorRank = 0,
        ?ApiException $age = null,
    ): ?array {
        $refusal = OwnershipTransferRule::refusal(
            self::ACTOR,
            $managesAnyRoom,
            $actorRank,
            $targetId,
            $targetRole,
            $eligible,
            $ownerId,
            $ownerActive,
            $ownerRank,
            static fn (): ?ApiException => $age,
        );

        return $refusal === null ? null : [$refusal->status, $refusal->errorCode];
    }
}
