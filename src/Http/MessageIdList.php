<?php

declare(strict_types=1);

namespace ChitChat\Http;

final class MessageIdList
{
    /** @return list<int> */
    public static function fromQuery(mixed $value, int $maximum = 100): array
    {
        if (!is_string($value) || $value === '') {
            throw new ApiException(400, 'validation_error', 'message_ids must be a comma-separated list.');
        }

        $parts = explode(',', $value);
        if (count($parts) > $maximum) {
            throw new ApiException(400, 'validation_error', "message_ids must contain at most {$maximum} message IDs.");
        }

        $ids = [];
        foreach ($parts as $part) {
            if (preg_match('/\A[1-9][0-9]*\z/D', $part) !== 1) {
                throw new ApiException(400, 'validation_error', 'message_ids must contain positive integers.');
            }
            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    }
}
