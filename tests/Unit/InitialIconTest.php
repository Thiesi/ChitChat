<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\InstallationIcon\IconRenderer;
use ChitChat\InstallationIcon\InitialIcon;
use PHPUnit\Framework\TestCase;

final class InitialIconTest extends TestCase
{
    protected function setUp(): void
    {
        if (!IconRenderer::available() || !function_exists('imagettftext')) {
            self::markTestSkipped('GD with FreeType is unavailable.');
        }
    }

    public function testInitialUsesTheTrimmedUppercaseFirstCharacterAndRealFontCoverage(): void
    {
        $initial = new InitialIcon();
        self::assertSame('C', $initial->character('  cedar house  '));
        self::assertSame("\u{00C9}", $initial->character("\u{00E9}lan"));
        self::assertSame('7', $initial->character('7 Seas'));
        self::assertNull($initial->character('   '));
        self::assertNull($initial->character("\u{4E2D} Chat"));
        self::assertNull($initial->character("\u{1F680} Chat"));
        // An uppercase expansion is not silently truncated or rendered as two letters.
        self::assertNull($initial->character("\u{00DF} Chat"));
        try { $initial->sources("\u{4E2D} Chat"); self::fail('A missing glyph became a replacement box.'); }
        catch (ApiException $error) { self::assertSame(409, $error->status); self::assertSame('preset_unavailable', $error->errorCode); }
    }

    public function testAuthoredFullAndCompactProfilesKeepTransparentCanvases(): void
    {
        $sources = (new InitialIcon())->sources('terrace');
        self::assertSame('T', $sources['initial']);
        foreach (['full' => 1024, 'compact' => 256] as $profile => $side) {
            $image = imagecreatefromstring($sources[$profile]);
            self::assertNotFalse($image);
            self::assertSame($side, imagesx($image));
            self::assertSame($side, imagesy($image));
            self::assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 127);
            // Fixed pixel/em geometry must not be enlarged by treating pixels as GD points.
            $minX = $minY = $side;
            $maxX = $maxY = -1;
            for ($y = 0; $y < $side; ++$y) {
                for ($x = 0; $x < $side; ++$x) {
                    if (((imagecolorat($image, $x, $y) >> 24) & 127) < 127) {
                        $minX = min($minX, $x); $maxX = max($maxX, $x);
                        $minY = min($minY, $y); $maxY = max($maxY, $y);
                    }
                }
            }
            $expectedWidth = $profile === 'full' ? 241 : 81;
            $expectedHeight = $profile === 'full' ? 325 : 110;
            self::assertEqualsWithDelta($expectedWidth, $maxX - $minX + 1, 4, 'Supplied font and authored pixel size, allowing FreeType rasterization differences.');
            self::assertEqualsWithDelta($expectedHeight, $maxY - $minY + 1, 4);
            imagedestroy($image);
        }
    }
}
