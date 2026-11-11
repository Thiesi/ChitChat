<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use ChitChat\Http\ApiException;
use GdImage;
use RuntimeException;
use Throwable;

/** Pure bounded GD rendering: no files, database, sessions or HTTP output. */
final class IconRenderer
{
    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng')
            && function_exists('imagecreatetruecolor') && function_exists('imagecreatefromwebp');
    }

    public static function background(string $value): string
    {
        if (preg_match('/^#[0-9a-fA-F]{3}$/D', $value) === 1) {
            $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
            throw new ApiException(400, 'validation_error', 'background must be #RGB or #RRGGBB.');
        }
        return strtoupper($value);
    }

    public function create(string $bytes, string $background, ?IconGeneration $previous = null): IconGeneration
    {
        $background = self::background($background);
        if (!self::available()) {
            throw new ApiException(503, 'application_icon_unavailable', 'Installation icon rendering requires GD with PNG and WebP.');
        }
        $header = @getimagesizefromstring($bytes);
        if (!is_array($header) || !in_array($header['mime'], ['image/png', 'image/webp'], true)
            || $header[0] !== $header[1] || $header[0] < 512 || $header[0] > 4000) {
            throw new ApiException(400, 'validation_error', 'Choose a square PNG or WebP between 512 and 4000 pixels.');
        }
        $memoryLimit = ini_parse_quantity(ini_get('memory_limit') ?: '-1');
        $estimate = $header[0] * $header[1] * 8 + strlen($bytes) + 32 * 1024 * 1024;
        if ($memoryLimit > 0 && memory_get_usage(true) + $estimate >= $memoryLimit) {
            throw new ApiException(400, 'validation_error', 'The icon exceeds the available rendering memory.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof GdImage || imagesx($source) !== $header[0] || imagesy($source) !== $header[1]) {
            throw new ApiException(400, 'validation_error', 'The icon could not be decoded.');
        }
        try {
            $normal = $this->canvas(512);
            try {
                if (!imagecopyresampled($normal, $source, 0, 0, 0, 0, 512, 512, $header[0], $header[1])) {
                    throw new RuntimeException('Unable to resize installation icon.');
                }
                $maxRadius = 0.0;
                for ($y = 0; $y < 512; ++$y) {
                    for ($x = 0; $x < 512; ++$x) {
                        // GD inverse alpha is seven bits. Ignore opacity below roughly 16/255.
                        if (((imagecolorat($normal, $x, $y) >> 24) & 127) > 119) {
                            continue;
                        }
                        // Include the furthest pixel corner, not just its centre.
                        $radius = hypot(abs($x + 0.5 - 256) + 0.5, abs($y + 0.5 - 256) + 0.5);
                        $maxRadius = max($maxRadius, $radius);
                    }
                }
                $scale = $maxRadius > 0 ? min(1.0, 203.0 / $maxRadius) : 1.0;
                $maskSide = max(1, (int) floor(512 * $scale));
                $output = [];
                foreach (IconGeneration::VARIANTS as $variant => $spec) {
                    if ($variant === 'ico') {
                        $png = $output['favicon'] ?? throw new RuntimeException('Missing favicon rendition.');
                        $output['ico'] = pack('vvv', 0, 1, 1)
                            . pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22) . $png;
                        continue;
                    }
                    $flatten = in_array($variant, ['apple_touch', 'maskable'], true);
                    $target = $this->canvas($spec['size'], $flatten ? $background : null);
                    try {
                        $side = $variant === 'maskable' ? $maskSide : $spec['size'];
                        $offset = intdiv($spec['size'] - $side, 2);
                        if (!imagecopyresampled($target, $normal, $offset, $offset, 0, 0, $side, $side, 512, 512)) {
                            throw new RuntimeException('Unable to render installation icon.');
                        }
                        $output[$variant] = $this->png($target);
                    } finally {
                        imagedestroy($target);
                    }
                }
                if ($previous !== null) {
                    foreach (['favicon', 'icon_192', 'icon_512', 'ico'] as $variant) {
                        $output[$variant] = $previous->bytes[$variant];
                    }
                }
                return new IconGeneration($output, $background, $previous === null ? $header[0] : $previous->sourceWidth,
                    $previous === null ? $header[1] : $previous->sourceHeight, $maskSide / 512);
            } finally {
                imagedestroy($normal);
            }
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ApiException(400, 'validation_error', 'The icon could not be rendered.');
        } finally {
            imagedestroy($source);
        }
    }

    /** Authored adaptive canvases already have their approved size and optical offset.
     * Scale the whole canvas; never refit or recenter the foreground.
     */
    public function preset(string $bytes, string $background, ?string $compact = null): IconGeneration
    {
        $background = self::background($background);
        if (!self::available()) {
            throw new ApiException(409, 'preset_unavailable', 'This icon cannot be drawn on this server right now.');
        }
        $source = $this->presetCanvas($bytes);
        $small = null;
        try {
            if ($compact !== null) $small = $this->presetCanvas($compact, 256);
            $output = [];
            foreach (IconGeneration::VARIANTS as $variant => $spec) {
                if ($variant === 'ico') {
                    $png = $output['favicon'] ?? throw new RuntimeException('Missing favicon rendition.');
                    $output['ico'] = pack('vvv', 0, 1, 1)
                        . pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($png), 22) . $png;
                    continue;
                }
                $drawing = $spec['size'] <= 32 && $small !== null ? $small : $source;
                $target = $this->canvas($spec['size'], $background);
                try {
                    if (!imagecopyresampled($target, $drawing, 0, 0, 0, 0,
                        $spec['size'], $spec['size'], imagesx($drawing), imagesy($drawing))) {
                        throw new RuntimeException('Unable to render a packaged icon.');
                    }
                    $output[$variant] = $this->png($target);
                } finally { imagedestroy($target); }
            }
            return new IconGeneration($output, $background, 1024, 1024, 1.0);
        } finally {
            if ($small !== null) imagedestroy($small);
            imagedestroy($source);
        }
    }

    /** Bounded catalog thumbnail from the same authored source as publication.
     * @param 256|1024 $sourceSide
     */
    public function presetThumbnail(string $bytes, string $background, int $sourceSide = 1024): string
    {
        $source = $this->presetCanvas($bytes, $sourceSide);
        try {
            $target = $this->canvas(32, self::background($background));
            try {
                if (!imagecopyresampled($target, $source, 0, 0, 0, 0, 32, 32, $sourceSide, $sourceSide)) {
                    throw new RuntimeException('Unable to draw an icon thumbnail.');
                }
                return $this->png($target);
            } finally { imagedestroy($target); }
        } finally { imagedestroy($source); }
    }

    /** @param 256|1024 $side */
    private function presetCanvas(string $bytes, int $side = 1024): GdImage
    {
        if (!self::available() || strlen($bytes) > IconGeneration::MAX_BYTES) {
            throw new ApiException(409, 'preset_unavailable', 'This icon cannot be drawn on this server right now.');
        }
        $header = @getimagesizefromstring($bytes);
        if (!is_array($header) || $header['mime'] !== 'image/png' || $header[0] !== $side || $header[1] !== $side) {
            throw new RuntimeException('A packaged preset must match its authored PNG canvas dimensions.');
        }
        $limit = ini_parse_quantity(ini_get('memory_limit') ?: '-1');
        if ($limit > 0 && memory_get_usage(true) + 32 * 1024 * 1024 >= $limit) {
            throw new ApiException(409, 'preset_unavailable', 'There is not enough rendering memory for this icon right now.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source instanceof GdImage) throw new RuntimeException('A packaged preset cannot be decoded.');
        return $source;
    }

    /** @param int<1,max> $side */
    private function canvas(int $side, ?string $background = null): GdImage
    {
        $image = imagecreatetruecolor($side, $side);
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Unable to allocate installation icon.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, $background === null);
        $rgb = $background === null ? 0 : (int) hexdec(substr($background, 1));
        $color = imagecolorallocatealpha($image, ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255,
            $background === null ? 127 : 0);
        if ($color === false || !imagefill($image, 0, 0, $color)) {
            imagedestroy($image);
            throw new RuntimeException('Unable to fill installation icon.');
        }
        imagealphablending($image, $background !== null);
        return $image;
    }

    private function png(GdImage $image): string
    {
        ob_start();
        try {
            $ok = imagepng($image, null, 9);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!$ok || !is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Unable to encode installation icon.');
        }
        return $bytes;
    }
}
