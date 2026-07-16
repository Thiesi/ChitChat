<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\BirthDate;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BirthDateTest extends TestCase
{
    public function testValidBirthDateIsNormalized(): void
    {
        self::assertSame('1990-05-12', BirthDate::normalize('1990-05-12'));
        self::assertNull(BirthDate::normalize(null));
    }

    public function testFutureBirthDateIsRejected(): void
    {
        $this->expectException(ApiException::class);
        BirthDate::normalize((new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d'));
    }
}
