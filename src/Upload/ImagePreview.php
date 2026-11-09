<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use GdImage;
use Throwable;

/** Header dimensions survive unavailable/unsafe rendering; originals are never changed. */
final class ImagePreview
{
    public const MAX_EDGE = 1024;
    public const MAX_SOURCE_AXIS = 6000;
    public const MAX_SOURCE_PIXELS = 16_000_000;
    public const MAX_BYTES = 4_194_304;

    /** @return array{width:?int,height:?int,bytes:?string,preview_width:?int,preview_height:?int} */
    public function create(string $path, string $mime): array
    {
        $result = ['width' => null, 'height' => null, 'bytes' => null, 'preview_width' => null, 'preview_height' => null];
        if (!AttachmentPolicy::isPreviewable($mime)) {
            return $result;
        }
        $header = @getimagesize($path);
        if (!is_array($header) || $header['mime'] !== $mime
            || $header[0] < 1 || $header[1] < 1 || $header[0] > 2_147_483_647 || $header[1] > 2_147_483_647) {
            return $result;
        }
        $width = $header[0];
        $height = $header[1];
        $orientation = $mime === 'image/jpeg' ? JpegOrientation::read($path) : 1;
        if ($orientation === null) {
            return $result;
        }
        $result['width'] = $orientation >= 5 ? $height : $width;
        $result['height'] = $orientation >= 5 ? $width : $height;
        $decoder = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/gif' => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            default => '',
        };
        if ($width > self::MAX_SOURCE_AXIS || $height > self::MAX_SOURCE_AXIS
            || $width * $height > self::MAX_SOURCE_PIXELS || !function_exists($decoder)
            || !function_exists('imagewebp') || !function_exists('imagecreatetruecolor')
            || !$this->memoryFits($path, $width, $height)) {
            return $result;
        }
        $source = null;
        $target = null;
        try {
            $source = @$decoder($path);
            if (!$source instanceof GdImage || imagesx($source) !== $width || imagesy($source) !== $height) {
                return $result;
            }
            $scale = min(1.0, self::MAX_EDGE / max($width, $height));
            $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            if (!$target instanceof GdImage) {
                return $result;
            }
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            if ($transparent === false) {
                return $result;
            }
            imagefill($target, 0, 0, $transparent);
            if (!imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height)) {
                return $result;
            }
            imagedestroy($source);
            $source = null;
            // Scale before rotation: never allocate a second full-sized decoded source.
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($target, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($target, IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) {
                3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0,
            };
            if ($angle !== 0) {
                $rotated = imagerotate($target, $angle, $transparent);
                if (!$rotated instanceof GdImage) {
                    return $result;
                }
                imagedestroy($target);
                $target = $rotated;
                imagesavealpha($target, true);
            }
            ob_start();
            try {
                $written = imagewebp($target, null, 80);
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (!$written || !is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                return $result;
            }
            $result['bytes'] = $bytes;
            $result['preview_width'] = imagesx($target);
            $result['preview_height'] = imagesy($target);
            return $result;
        } catch (Throwable) {
            return $result;
        } finally {
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
            if ($target instanceof GdImage) {
                imagedestroy($target);
            }
        }
    }

    private function memoryFits(string $path, int $width, int $height): bool
    {
        $limit = ini_parse_quantity(ini_get('memory_limit') ?: '-1');
        if ($limit <= 0) {
            return true;
        }
        $size = @filesize($path);
        // Conservative codec/decoded pixels plus bounded target, rotation and encoding work.
        $estimate = $width * $height * 8 + ($size === false ? 0 : $size) + 32 * 1024 * 1024;
        return memory_get_usage(true) + $estimate < $limit;
    }
}
