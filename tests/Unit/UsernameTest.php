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

    public function testPlaceholdersAreValidRandomAndRecognisedCaseInsensitively(): void
    {
        $seen = [];
        for ($index = 0; $index < 200; $index++) {
            $placeholder = Username::placeholder();
            self::assertMatchesRegularExpression('/\Amember-[a-z2-7]{6}\z/', $placeholder);
            self::assertSame($placeholder, Username::display($placeholder), 'A placeholder is a valid username.');
            self::assertTrue(Username::isPlaceholder($placeholder));
            $seen[$placeholder] = true;
        }
        self::assertGreaterThan(190, count($seen), 'Placeholders come from a large random space.');
        self::assertTrue(Username::isPlaceholder(' MEMBER-AbC234 '));
        foreach (['member-abc1', 'member-abcdefg', 'member-abcde1', 'member_abcdef', 'xmember-abcdef', 'member-abcde8'] as $ordinary) {
            self::assertFalse(Username::isPlaceholder($ordinary), $ordinary);
        }
    }
}
