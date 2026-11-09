<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\View\HeadAssets;
use PHPUnit\Framework\TestCase;

final class IconHeadTest extends TestCase
{
    public function testDefaultAssetsRemainAndCustomAttributesAreEscapedWithoutACompetingSvg(): void
    {
        $default = HeadAssets::html();
        self::assertStringContainsString('href="/favicon.svg"',$default);
        self::assertStringContainsString('/assets/app-icons/apple-touch-icon.png',$default);
        self::assertSame($default,HeadAssets::html(null));
        $custom = HeadAssets::html(['favicon_url'=>'/installation-icon.php?a="&b=<',
            'mark_url'=>'/unused','apple_touch_url'=>'/apple?a="&b=<']);
        self::assertStringNotContainsString('/favicon.svg',$custom);
        self::assertStringContainsString('/installation-icon.php?a=&quot;&amp;b=&lt;',$custom);
        self::assertStringContainsString('/apple?a=&quot;&amp;b=&lt;',$custom);
        self::assertStringContainsString('href="/manifest.php"',$custom);
    }
}
