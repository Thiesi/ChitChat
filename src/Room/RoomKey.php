<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Http\ApiException;

final class RoomKey
{
    public static function normalize(string $key): string
    {
        $key = strtolower(trim($key));
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{2,47}\z/D', $key) !== 1) {
            throw new ApiException(
                400,
                'invalid_room_key',
                'Room key must be 3-48 lowercase characters using letters, numbers, underscores, or hyphens.',
            );
        }

        return $key;
    }
}
