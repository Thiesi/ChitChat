<?php

declare(strict_types=1);

namespace ChitChat\Tests\Support;

use GdImage;
use RuntimeException;

final class TestImages
{
    public static function png(int $width = 2048, int $height = 1024): string
    {
        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Unable to create PNG fixture.');
        }
        imagefill($image, 0, 0, imagecolorallocate($image, 230, 90, 40));
        ob_start();
        try {
            imagepng($image);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!is_string($bytes)) {
            throw new RuntimeException('Unable to encode PNG fixture.');
        }
        return $bytes;
    }

    public static function jpeg(int $orientation, bool $little = true): string
    {
        $image = imagecreatetruecolor(100, 60);
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Unable to create JPEG fixture.');
        }
        foreach ([[0, 0, 49, 29, 255, 0, 0], [50, 0, 99, 29, 0, 255, 0],
            [0, 30, 49, 59, 0, 0, 255], [50, 30, 99, 59, 255, 255, 0]] as $rectangle) {
            [$left, $top, $right, $bottom, $red, $green, $blue] = $rectangle;
            imagefilledrectangle($image, $left, $top, $right, $bottom, imagecolorallocate($image, $red, $green, $blue));
        }
        ob_start();
        try {
            imagejpeg($image, null, 100);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!is_string($bytes)) {
            throw new RuntimeException('Unable to encode JPEG fixture.');
        }
        $tiff = $little
            ? 'II' . pack('vVv', 42, 8, 1) . pack('vvVv', 0x0112, 3, 1, $orientation) . "\0\0" . pack('V', 0)
            : 'MM' . pack('nNn', 42, 8, 1) . pack('nnNn', 0x0112, 3, 1, $orientation) . "\0\0" . pack('N', 0);
        $exif = "Exif\0\0" . $tiff;
        $comment = 'private GPS/camera metadata';
        return substr($bytes, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif
            . "\xff\xfe" . pack('n', strlen($comment) + 2) . $comment . substr($bytes, 2);
    }

    /** Header-only fixtures must be rejected before any oversized GD decode. */
    public static function pngHeader(int $width, int $height): string
    {
        $data = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
        return "\x89PNG\r\n\x1a\n" . pack('N', strlen($data)) . 'IHDR' . $data
            . pack('N', crc32('IHDR' . $data)) . "\0\0\0\0IEND\xae\x42\x60\x82";
    }

    /** Two frames, first red then blue. GD must use only the first frame. */
    public static function animatedGif(): string
    {
        $descriptor = "\x2c\0\0\0\0\x01\0\x01\0\0";
        return "GIF89a\x01\0\x01\0\x80\0\0\xff\0\0\0\0\xff"
            . $descriptor . "\x02\x02\x44\x01\0"
            . $descriptor . "\x02\x02\x4c\x01\0\x3b";
    }
}
