<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Http\ApiException;
use Closure;

/**
 * Whether a room may be handed to a person (#273), as one rule for the transfer itself and for
 * the flags that offer it (#302). It decides from facts the caller read: under locks for the
 * transfer, without them for a flag, which can therefore go stale. Returns the refusal the
 * endpoint gives, in the endpoint's order, or null when the transfer is allowed. Step-up and
 * "the room changed hands meanwhile" are checked by the transfer only.
 */
final class OwnershipTransferRule
{
    /**
     * @param ?string $targetRole the target's role in the room, null when not a member
     * @param bool $targetEligible an active, registered account in good standing (see OwnershipTransferOptions for viewers who can't see bans)
     * @param ?int $ownerId the current owner, null when the room has none
     * @param Closure(): ?ApiException $ageRefusal checked only when the actor takes over a room they aren't in
     */
    public static function refusal(
        int $actorId,
        bool $actorManagesAnyRoom,
        int $actorGlobalRank,
        int $targetId,
        ?string $targetRole,
        bool $targetEligible,
        ?int $ownerId,
        bool $ownerActive,
        int $ownerGlobalRank,
        Closure $ageRefusal,
    ): ?ApiException {
        if (!$actorManagesAnyRoom && $ownerId !== $actorId) {
            return new ApiException(403, 'forbidden', 'Only the room owner or an administrator can transfer ownership.');
        }
        if ($targetRole === 'owner') {
            return new ApiException(409, 'already_owner', 'That member already owns this room.');
        }
        $takeover = $targetId === $actorId && $actorManagesAnyRoom;
        if ($targetRole === null && !$takeover) {
            return new ApiException(404, 'membership_not_found', 'Target user is not a room member.');
        }
        if (!$targetEligible) {
            return new ApiException(409, 'transfer_target_not_eligible', 'Only an active, registered account in good standing can own a room.');
        }
        if ($ownerId !== null && $ownerActive && $ownerId !== $actorId && $actorGlobalRank < $ownerGlobalRank) {
            return new ApiException(403, 'forbidden', 'You cannot take a room away from an owner with a higher role than yours.');
        }
        if ($targetRole === null) {
            // An administrator taking over a room they are not in: the room's age rule still applies.
            return $ageRefusal();
        }

        return null;
    }
}
