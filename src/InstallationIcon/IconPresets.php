<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use ChitChat\Http\ApiException;
use RuntimeException;

/** The approved sheet in picker order. Paths are packaged constants, never user input. */
final readonly class IconPresets
{
    public const INITIAL_NOTICE = "The new name's first letter can't be drawn in the icon; choose another icon.";
    private const DEFINITIONS = [
        'lantern' => ['label' => 'ChitChat icon', 'background' => '#14111C', 'compact' => false],
        'lantern-paper' => ['label' => 'Lantern paper', 'background' => '#FBF7F1', 'compact' => true],
        'lounge' => ['label' => 'Lounge', 'background' => '#111E22', 'compact' => true],
        'dusk' => ['label' => 'Dusk', 'background' => '#141729', 'compact' => true],
        'ember' => ['label' => 'Ember', 'background' => '#1D1612', 'compact' => true],
        'rose' => ['label' => 'Rosé', 'background' => '#1D1419', 'compact' => true],
        'voices' => ['label' => 'Two voices', 'background' => '#213D38', 'compact' => false],
        'spark' => ['label' => 'Spark', 'background' => '#22274A', 'compact' => false],
        'initial' => ['label' => 'Your initial', 'background' => '#F06A3B', 'compact' => true],
    ];
    private const ROOT = __DIR__ . '/../../resources/installation-icon-presets/';

    /** @return array{label:string,background:string,compact:bool} */
    public function definition(string $id): array
    {
        return self::DEFINITIONS[$id] ?? throw new ApiException(400, 'preset_unknown', 'Choose an icon from the available presets.');
    }

    /** @return array{full:string,compact:?string,initial:?string} */
    public function sources(string $id, string $name): array
    {
        $definition = $this->definition($id);
        if ($id === 'lantern') throw new RuntimeException('The ChitChat preset is a reset, not a stored generation.');
        if ($id === 'initial') return (new InitialIcon())->sources($name);
        if (!IconRenderer::available()) throw new ApiException(409, 'preset_unavailable', 'This icon cannot be drawn on this server right now.');
        return ['full' => $this->read(self::ROOT . $id . '.png'),
            'compact' => $definition['compact'] ? $this->read(self::ROOT . $id . '-compact.png') : null, 'initial' => null];
    }

    /** @return list<array{id:string,label:string,suggested_background:string,available:bool,thumbnail_url:?string,initial?:string}> */
    public function catalog(string $name): array
    {
        $result = [];
        foreach (self::DEFINITIONS as $id => $definition) {
            $row = ['id' => $id, 'label' => $definition['label'], 'suggested_background' => $definition['background'],
                'available' => false, 'thumbnail_url' => null];
            if ($id === 'lantern') {
                $row['available'] = true;
                $row['thumbnail_url'] = '/assets/app-icons/favicon-32.png';
            } else {
                try {
                    $sources = $this->sources($id, $name);
                    $png = (new IconRenderer())->presetThumbnail($sources['compact'] ?? $sources['full'], $definition['background'], $sources['compact'] === null ? 1024 : 256);
                    $row['thumbnail_url'] = 'data:image/png;base64,' . base64_encode($png);
                    $row['available'] = true;
                    if ($sources['initial'] !== null) $row['initial'] = $sources['initial'];
                } catch (ApiException) {
                    // An unavailable source/font is not offered by the picker.
                }
            }
            $result[] = $row;
        }
        return $result;
    }

    private function read(string $path): string
    {
        $bytes = @file_get_contents($path, false, null, 0, IconGeneration::MAX_BYTES + 1);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > IconGeneration::MAX_BYTES) {
            throw new ApiException(409, 'preset_unavailable', 'This icon is unavailable on this server right now.');
        }
        return $bytes;
    }
}
