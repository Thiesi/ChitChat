<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\Room\RoomKey;
use PHPUnit\Framework\TestCase;

final class RoomKeyTest extends TestCase
{
    public function testRoomKeyIsLowercased(): void
    {
        self::assertSame('general_chat', RoomKey::normalize('General_Chat'));
    }

    public function testInvalidRoomKeyIsRejected(): void
    {
        $this->expectException(ApiException::class);
        RoomKey::normalize('not allowed!');
    }
}
