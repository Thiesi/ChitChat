<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Http\ApiException;
use ChitChat\InstallationIcon\IconRenderer;
use ChitChat\Tests\Support\TestImages;
use PHPUnit\Framework\TestCase;

final class IconRendererTest extends TestCase
{
    protected function setUp(): void
    {
        if (!IconRenderer::available()) self::markTestSkipped('GD with PNG and WebP is unavailable.');
    }

    public function testOpaqueSquareFitsTheMaskableCircleAndMetadataIsStripped(): void
    {
        $source = TestImages::png(600, 600);
        $generation = (new IconRenderer())->create($source . 'private trailing source metadata', '#abc');
        self::assertSame('#AABBCC', $generation->background);
        self::assertSame(600, $generation->sourceWidth);
        self::assertLessThan(0.57, $generation->maskableScale);
        self::assertGreaterThan(0.55, $generation->maskableScale);
        foreach ($generation->bytes as $variant => $bytes) {
            self::assertStringNotContainsString('private trailing', $bytes);
            self::assertSame(hash('sha256', $bytes), $generation->renditions()[$variant]['sha256']);
        }
        $mask = imagecreatefromstring($generation->bytes['maskable']);
        self::assertNotFalse($mask);
        $bg = imagecolorat($mask, 0, 0) & 0xffffff;
        self::assertSame(0xAABBCC, $bg);
        $furthest = 0.0;
        for ($y = 0; $y < 512; ++$y) {
            for ($x = 0; $x < 512; ++$x) {
                if ((imagecolorat($mask, $x, $y) & 0xffffff) !== $bg) {
                    $furthest = max($furthest, hypot($x + 0.5 - 256, $y + 0.5 - 256));
                }
            }
        }
        self::assertLessThanOrEqual(204.8, $furthest);
        imagedestroy($mask);
        self::assertSame("\0\0\1\0\1\0", substr($generation->bytes['ico'], 0, 6));
        self::assertSame($generation->bytes['favicon'], substr($generation->bytes['ico'], 22));
    }

    public function testSmallTransparentArtIsNotUpscaledAndBackgroundOnlyPreservesSource(): void
    {
        $image = imagecreatetruecolor(600, 600);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 300, 300, 100, 100, imagecolorallocatealpha($image, 255, 0, 0, 0));
        ob_start();
        imagepng($image);
        $source = ob_get_clean();
        imagedestroy($image);
        self::assertIsString($source);
        $renderer = new IconRenderer();
        $first = $renderer->create($source, '#14111C');
        self::assertSame(1.0, $first->maskableScale);
        $second = $renderer->create($first->bytes['icon_512'], '#FBF7F1', $first);
        self::assertSame(600, $second->sourceWidth);
        self::assertNotSame($first->revision, $second->revision);
        foreach (['favicon', 'icon_192', 'icon_512', 'ico'] as $variant) {
            self::assertSame($first->bytes[$variant], $second->bytes[$variant]);
        }
        foreach (['apple_touch', 'maskable'] as $variant) {
            self::assertNotSame($first->bytes[$variant], $second->bytes[$variant]);
            $decoded = imagecreatefromstring($second->bytes[$variant]);
            self::assertNotFalse($decoded);
            self::assertSame(0, (imagecolorat($decoded, 0, 0) >> 24) & 127);
            self::assertSame(0xFBF7F1, imagecolorat($decoded, 0, 0) & 0xffffff);
            imagedestroy($decoded);
        }
    }

    public function testInvalidAndTruncatedImagesAreRejectedBeforeReplacingAnything(): void
    {
        foreach (['garbage', substr(TestImages::png(512,512), 0, 35),
            TestImages::png(511,511), TestImages::png(512,600), TestImages::png(4001,1)] as $bytes) {
            try {
                (new IconRenderer())->create($bytes, '#14111C');
                self::fail('Invalid icon accepted.');
            } catch (ApiException $e) {
                self::assertSame('validation_error', $e->errorCode);
            }
        }
        $this->expectException(ApiException::class);
        IconRenderer::background('#nothex');
    }
}
