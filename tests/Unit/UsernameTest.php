<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Username;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class UsernameTest extends TestCase
{
    public function testCanonicalUsernameIsCaseInsensitive(): void
    {
        self::assertSame('alice.example', Username::canonical('Alice.Example'));
    }

    public function testInvalidUsernameIsRejected(): void
    {
        $this->expectException(ApiException::class);
        Username::display('not allowed!');
    }
}
