<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use DateTimeImmutable;

final readonly class RoomEligibility
{
    public function __construct(private RoomRepository $rooms)
    {
    }

    public function requireMinimumAge(AuthenticatedUser $actor, Room $room): void
    {
        if ($room->minimumAge === 0 || RoomAuthorization::canModerateAnyRoom($actor)) {
            return;
        }

        $birthDate = $this->rooms->birthDateForUser($actor->id);
        if ($birthDate === null) {
            throw new ApiException(403, 'birth_date_required', 'A birth date is required to access this room.');
        }

        $age = (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y;
        if ($age < $room->minimumAge) {
            throw new ApiException(403, 'minimum_age_not_met', 'You do not meet this room’s minimum age.');
        }
    }
}
