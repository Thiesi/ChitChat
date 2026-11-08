<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Tests\Support\TestImages;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Upload\ImagePreview;
use ChitChat\Upload\JpegOrientation;
use GdImage;
use PHPUnit\Framework\TestCase;

final class ImagePreviewTest extends TestCase
{
    public function testPreviewIsBoundedWithoutUpscalingAndOriginalBytesStayIntact(): void
    {
        $this->requireGd();
        foreach ([[2048, 1024, 1024, 512], [20, 10, 20, 10], [100, 200, 100, 200]] as [$width, $height, $expectedW, $expectedH]) {
            $bytes = TestImages::png($width, $height);
            $path = $this->source($bytes);
            try {
                $result = (new ImagePreview())->create($path, 'image/png');
                self::assertSame([$width, $height], [$result['width'], $result['height']]);
                self::assertSame([$expectedW, $expectedH], [$result['preview_width'], $result['preview_height']]);
                self::assertIsString($result['bytes']);
                $size = getimagesizefromstring($result['bytes']);
                self::assertIsArray($size);
                self::assertSame('image/webp', $size['mime']);
                self::assertSame([$expectedW, $expectedH], [$size[0], $size[1]]);
                self::assertSame($bytes, file_get_contents($path));
            } finally {
                TestScratch::remove($path);
            }
        }
    }

    public function testEveryExifOrientationAndByteOrderHasCorrectDisplayPixelsAndNoMetadata(): void
    {
        $this->requireGd();
        $expected = [1 => [0, 1, 2, 3], 2 => [1, 0, 3, 2], 3 => [3, 2, 1, 0], 4 => [2, 3, 0, 1],
            5 => [0, 2, 1, 3], 6 => [2, 0, 3, 1], 7 => [3, 1, 2, 0], 8 => [1, 3, 0, 2]];
        foreach ([true, false] as $little) {
            foreach ($expected as $orientation => $corners) {
                $path = $this->source(TestImages::jpeg($orientation, $little));
                try {
                    self::assertSame($orientation, JpegOrientation::read($path));
                    $result = (new ImagePreview())->create($path, 'image/jpeg');
                    $dimensions = $orientation >= 5 ? [60, 100] : [100, 60];
                    self::assertSame($dimensions, [$result['width'], $result['height']]);
                    self::assertSame($dimensions, [$result['preview_width'], $result['preview_height']]);
                    self::assertIsString($result['bytes']);
                    self::assertStringNotContainsString('private GPS/camera metadata', $result['bytes']);
                    self::assertStringNotContainsString('Exif', $result['bytes']);
                    $image = imagecreatefromstring($result['bytes']);
                    self::assertInstanceOf(GdImage::class, $image);
                    try {
                        foreach ([[1, 1], [3, 1], [1, 3], [3, 3]] as $index => [$x, $y]) {
                            $pixel = imagecolorat($image, (int) ($dimensions[0] * $x / 4), (int) ($dimensions[1] * $y / 4));
                            self::assertNotFalse($pixel);
                            self::assertSame($corners[$index], $this->nearestColor($pixel), 'Orientation ' . $orientation);
                        }
                    } finally {
                        imagedestroy($image);
                    }
                } finally {
                    TestScratch::remove($path);
                }
            }
        }
    }

    public function testOversizedAndOverPixelBudgetHeadersKeepDimensionsWithoutDecoding(): void
    {
        foreach ([[10000, 20000], [4001, 4000]] as [$width, $height]) {
            $path = $this->source(TestImages::pngHeader($width, $height));
            try {
                $result = (new ImagePreview())->create($path, 'image/png');
                self::assertSame([$width, $height], [$result['width'], $result['height']]);
                self::assertNull($result['bytes']);
            } finally {
                TestScratch::remove($path);
            }
        }
        $path = $this->source('not an image');
        try {
            self::assertNull((new ImagePreview())->create($path, 'image/png')['width']);
            self::assertNull((new ImagePreview())->create($path, 'text/plain')['bytes']);
        } finally {
            TestScratch::remove($path);
        }
    }

    public function testGifPreviewUsesStaticFirstFrame(): void
    {
        $this->requireGd();
        $path = $this->source(TestImages::animatedGif());
        try {
            $result = (new ImagePreview())->create($path, 'image/gif');
            self::assertIsString($result['bytes']);
            $image = imagecreatefromstring($result['bytes']);
            self::assertInstanceOf(GdImage::class, $image);
            try {
                $pixel = imagecolorat($image, 0, 0);
                self::assertNotFalse($pixel);
                self::assertSame(0, $this->nearestColor($pixel));
            } finally {
                imagedestroy($image);
            }
        } finally {
            TestScratch::remove($path);
        }
    }

    public function testMissingGdStillReturnsOrientedDimensions(): void
    {
        $this->requireGd();
        $path = $this->source(TestImages::jpeg(6));
        $output = TestScratch::file('image-header-without-gd');
        $errors = TestScratch::file('image-header-errors');
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; echo json_encode((new \ChitChat\Upload\ImagePreview())->create(' . var_export($path, true) . ', "image/jpeg"), JSON_THROW_ON_ERROR);';
        $process = proc_open([PHP_BINARY, '-n', '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $errors, 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        try {
            self::assertSame(0, proc_close($process), (string) file_get_contents($errors));
            $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([60, 100], [$result['width'], $result['height']]);
            self::assertNull($result['bytes']);
        } finally {
            TestScratch::remove($path);
            TestScratch::remove($output);
            TestScratch::remove($errors);
        }
    }

    public function testUntrustworthyAndOverlongExifHeadersDoNotInventDisplayGeometry(): void
    {
        $this->requireGd();
        $six = TestImages::jpeg(6);
        $one = TestImages::jpeg(1);
        $length = (ord($one[4]) << 8) | ord($one[5]);
        $conflicting = "\xff\xd8" . substr($one, 2, $length + 2) . substr($six, 2);
        $comment = "\xff\xfe" . pack('n', 65535) . str_repeat('x', 65533);
        $overlong = "\xff\xd8" . str_repeat($comment, 17) . substr($six, 2);
        foreach ([TestImages::jpeg(0), TestImages::jpeg(9), $conflicting, $overlong,
            "\xff\xd8\xff\xe1\0\x10Exif\0\0II"] as $bytes) {
            $path = $this->source($bytes);
            try {
                self::assertNull(JpegOrientation::read($path));
                $result = (new ImagePreview())->create($path, 'image/jpeg');
                self::assertNull($result['width']);
                self::assertNull($result['height']);
                self::assertNull($result['bytes']);
                self::assertSame($bytes, file_get_contents($path));
            } finally {
                TestScratch::remove($path);
            }
        }
    }
    private function requireGd(): void
    {
        if (!function_exists('imagewebp') || !function_exists('imagecreatefromjpeg')) {
            self::markTestSkipped('GD with WebP/JPEG is unavailable.');
        }
    }

    private function source(string $bytes): string
    {
        $path = TestScratch::file('preview-source');
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        return $path;
    }

    private function nearestColor(int $pixel): int
    {
        $colors = [[255, 0, 0], [0, 255, 0], [0, 0, 255], [255, 255, 0]];
        $distances = [];
        foreach ($colors as [$red, $green, $blue]) {
            $distances[] = abs((($pixel >> 16) & 255) - $red) + abs((($pixel >> 8) & 255) - $green) + abs(($pixel & 255) - $blue);
        }
        return (int) array_search(min($distances), $distances, true);
    }
}
