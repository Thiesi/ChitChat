<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Database\LikePattern;
use PHPUnit\Framework\TestCase;

final class LikePatternTest extends TestCase
{
    public function testPrefixIsCaseInsensitiveAndOnlyTheFinalWildcardIsUnescaped(): void
    {
        self::assertSame('ann%', LikePattern::prefix('AnN'));
        self::assertSame('%', LikePattern::prefix(''));
        self::assertSame('ann\_%', LikePattern::prefix('ANN_'));
        self::assertSame('ann\%%', LikePattern::prefix('ann%'));
        self::assertSame('ann\\\\%', LikePattern::prefix('ann\\'));
        self::assertSame('ann\_\%\\\\%', LikePattern::prefix('AnN_%\\'));
        self::assertSame('ann.member-%', LikePattern::prefix('ANN.Member-'));
    }
}
