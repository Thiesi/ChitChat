<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use ChitChat\Http\ApiException;
use DateTimeImmutable;

final class BirthDate
{
    public static function normalize(?string $birthDate): ?string
    {
        if ($birthDate === null || trim($birthDate) === '') {
            return null;
        }

        $value = trim($birthDate);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new ApiException(400, 'invalid_birth_date', 'birth_date must use YYYY-MM-DD.');
        }

        $today = new DateTimeImmutable('today');
        if ($date > $today) {
            throw new ApiException(400, 'invalid_birth_date', 'birth_date cannot be in the future.');
        }

        if ($date < $today->modify('-120 years')) {
            throw new ApiException(400, 'invalid_birth_date', 'birth_date is outside the supported range.');
        }

        return $value;
    }
}
