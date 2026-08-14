<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Mentions\MentionParser;
use PHPUnit\Framework\TestCase;

final class MentionParserTest extends TestCase
{
    public function testTokensExtractsLowercaseDeduplicatedUsernameShapedTokens(): void
    {
        $tokens = MentionParser::tokens('Hey @Alice, did you see @bob and @ALICE again? cc @a.b-c_9');

        self::assertSame(['alice', 'bob', 'a.b-c_9'], $tokens);
    }

    public function testTokensIgnoresEmailLikeAddressesAndTooShortHandles(): void
    {
        self::assertSame([], MentionParser::tokens('contact me at user@example.com'));
        self::assertSame([], MentionParser::tokens('@ab is too short'));
    }

    public function testTokensReturnsEmptyListWithoutAnyAtSign(): void
    {
        self::assertSame([], MentionParser::tokens('no mentions here'));
    }

    public function testContainsBroadcastTokenDetectsRoomAndHere(): void
    {
        self::assertTrue(MentionParser::containsBroadcastToken('@room please read this'));
        self::assertTrue(MentionParser::containsBroadcastToken('@here urgent'));
        self::assertFalse(MentionParser::containsBroadcastToken('@roommate is not a broadcast'));
        self::assertFalse(MentionParser::containsBroadcastToken('@alice only'));
    }
}
