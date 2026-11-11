<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use ChitChat\Http\ApiException;
use GdImage;
use Throwable;

/** The bundled font, its generated cmap coverage, and the visual lead's authored metrics. */
final readonly class InitialIcon
{
    private const INK = 0x14111C;
    // Authored sizes are pixels; GD TrueType uses points at its default 96 DPI.
    private const POINTS_PER_PIXEL = 72 / 96;
    private const ROOT = __DIR__ . '/../../resources/';

    public function character(string $name): ?string
    {
        $font = $this->font();
        $character = mb_strtoupper(mb_substr(trim($name), 0, 1, 'UTF-8'), 'UTF-8');
        if ($font === null || mb_strlen($character, 'UTF-8') !== 1) return null;
        $codepoint = mb_ord($character, 'UTF-8');
        if (!in_array($codepoint, $font['codepoints'], true)) return null;
        $box = @imagettfbbox($font['full']['font_size'] * self::POINTS_PER_PIXEL, 0, $font['path'], $character);
        // A mapped space/nonprinting glyph is not a drawable installation initial.
        if (!is_array($box) || max($box[0], $box[2], $box[4], $box[6]) <= min($box[0], $box[2], $box[4], $box[6])
            || max($box[1], $box[3], $box[5], $box[7]) <= min($box[1], $box[3], $box[5], $box[7])) return null;
        return $character;
    }

    /** @return array{full:string,compact:string,initial:string} */
    public function sources(string $name): array
    {
        $initial = $this->character($name);
        $font = $this->font();
        if ($initial === null || $font === null) {
            throw new ApiException(409, 'preset_unavailable', 'Your initial cannot draw the first character of this name. Choose another icon.');
        }
        return ['full' => $this->draw($font['path'], $initial, $font['full']),
            'compact' => $this->draw($font['path'], $initial, $font['compact']), 'initial' => $initial];
    }

    /** @return array{path:string,codepoints:list<int>,full:array{canvas:256|1024,font_size:float,offset_x:float,offset_y:float},compact:array{canvas:256|1024,font_size:float,offset_x:float,offset_y:float}}|null */
    private function font(): ?array
    {
        if (!IconRenderer::available() || !function_exists('imagettfbbox') || !function_exists('imagettftext')) return null;
        $path = self::ROOT . 'fonts/BricolageGrotesque-ExtraBold.ttf';
        $bytes = @file_get_contents($path, false, null, 0, 2_097_153);
        $coverage = @file_get_contents(self::ROOT . 'fonts/BricolageGrotesque-ExtraBold.coverage.json', false, null, 0, 131_073);
        $metrics = @file_get_contents(self::ROOT . 'installation-icon-presets/initial-metrics.json', false, null, 0, 4097);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 2_097_152
            || !is_string($coverage) || strlen($coverage) > 131_072 || !is_string($metrics) || strlen($metrics) > 4096) return null;
        try {
            $map = json_decode($coverage, true, 4, JSON_THROW_ON_ERROR);
            $geometry = json_decode($metrics, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) { return null; }
        if (!is_array($map) || !is_string($map['font_sha256'] ?? null)
            || !hash_equals($map['font_sha256'], hash('sha256', $bytes))
            || !is_array($map['codepoints'] ?? null) || !array_is_list($map['codepoints']) || count($map['codepoints']) > 8192
            || !is_array($geometry) || ($geometry['size_unit'] ?? null) !== 'px') return null;
        foreach ($map['codepoints'] as $point) {
            if (!is_int($point) || $point < 0 || $point > 0x10FFFF) return null;
        }
        $full = $this->profile($geometry['full'] ?? null, 1024);
        $compact = $this->profile($geometry['compact'] ?? null, 256);
        if ($full === null || $compact === null) return null;
        return ['path' => $path, 'codepoints' => $map['codepoints'], 'full' => $full, 'compact' => $compact];
    }

    /** @param 256|1024 $side
     * @return array{canvas:256|1024,font_size:float,offset_x:float,offset_y:float}|null
     */
    private function profile(mixed $value, int $side): ?array
    {
        if (!is_array($value) || ($value['canvas'] ?? null) !== $side) return null;
        foreach (['font_size', 'offset_x', 'offset_y'] as $key) {
            if (!isset($value[$key]) || (!is_int($value[$key]) && !is_float($value[$key])) || !is_finite((float) $value[$key])) return null;
        }
        if ($value['font_size'] < 64 || $value['font_size'] > 1024 || abs($value['offset_x']) > 256 || abs($value['offset_y']) > 256) return null;
        return ['canvas' => $side, 'font_size' => (float) $value['font_size'], 'offset_x' => (float) $value['offset_x'], 'offset_y' => (float) $value['offset_y']];
    }

    /** @param array{canvas:256|1024,font_size:float,offset_x:float,offset_y:float} $profile */
    private function draw(string $font, string $initial, array $profile): string
    {
        $side = $profile['canvas'];
        $canvas = imagecreatetruecolor($side, $side);
        if (!$canvas instanceof GdImage) throw new ApiException(409, 'preset_unavailable', 'This icon cannot be drawn right now.');
        try {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            if ($transparent === false || !imagefill($canvas, 0, 0, $transparent)) {
                throw new ApiException(409, 'preset_unavailable', 'This initial cannot be drawn.');
            }
            imagealphablending($canvas, true);
            $points = $profile['font_size'] * self::POINTS_PER_PIXEL;
            $box = imagettfbbox($points, 0, $font, $initial);
            if (!is_array($box)) throw new ApiException(409, 'preset_unavailable', 'This initial cannot be drawn.');
            $x = (int) round($side / 2 + $profile['offset_x'] - (min($box[0], $box[2], $box[4], $box[6]) + max($box[0], $box[2], $box[4], $box[6])) / 2);
            $y = (int) round($side / 2 + $profile['offset_y'] - (min($box[1], $box[3], $box[5], $box[7]) + max($box[1], $box[3], $box[5], $box[7])) / 2);
            $ink = imagecolorallocate($canvas, (self::INK >> 16) & 255, (self::INK >> 8) & 255, self::INK & 255);
            if ($ink === false || imagettftext($canvas, $points, 0, $x, $y, $ink, $font, $initial) === false) {
                throw new ApiException(409, 'preset_unavailable', 'This initial cannot be drawn.');
            }
            ob_start();
            try { $ok = imagepng($canvas, null, 9); $png = ob_get_contents(); }
            finally { ob_end_clean(); }
            if (!$ok || !is_string($png)) throw new ApiException(409, 'preset_unavailable', 'This initial cannot be drawn.');
            return $png;
        } finally { imagedestroy($canvas); }
    }
}
