<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use RuntimeException;

/** A complete immutable generation; uploaded source bytes never enter storage. */
final readonly class IconGeneration
{
    public const VARIANTS = [
        'favicon' => ['size' => 32, 'mime' => 'image/png', 'path' => '/assets/app-icons/favicon-32.png'],
        'apple_touch' => ['size' => 180, 'mime' => 'image/png', 'path' => '/assets/app-icons/apple-touch-icon.png'],
        'icon_192' => ['size' => 192, 'mime' => 'image/png', 'path' => '/assets/app-icons/icon-192.png'],
        'icon_512' => ['size' => 512, 'mime' => 'image/png', 'path' => '/assets/app-icons/icon-512.png'],
        'maskable' => ['size' => 512, 'mime' => 'image/png', 'path' => '/assets/app-icons/icon-maskable-512.png'],
        'ico' => ['size' => 32, 'mime' => 'image/x-icon', 'path' => '/assets/app-icons/favicon.ico'],
    ];
    public const MAX_BYTES = 4_194_304;
    public string $revision;

    /** @param array<string,string> $bytes */
    public function __construct(
        public array $bytes,
        public string $background,
        public int $sourceWidth,
        public int $sourceHeight,
        public float $maskableScale,
    ) {
        if (array_keys($bytes) !== array_keys(self::VARIANTS)
            || array_sum(array_map(strlen(...), $bytes)) > self::MAX_BYTES || in_array('', $bytes, true)) {
            throw new RuntimeException('Invalid installation icon generation.');
        }
        $parts = [$background, (string) $sourceWidth, (string) $sourceHeight, sprintf('%.9F', $maskableScale)];
        foreach ($bytes as $value) {
            $parts[] = hash('sha256', $value);
        }
        $this->revision = hash('sha256', implode("\n", $parts));
    }

    /** @return array<string,array{url:string,width:int,height:int,mime_type:string,size_bytes:int,sha256:string}> */
    public function renditions(): array
    {
        $result = [];
        foreach (self::VARIANTS as $name => $spec) {
            $result[$name] = [
                'url' => '/installation-icon.php?revision=' . $this->revision . '&variant=' . $name,
                'width' => $spec['size'], 'height' => $spec['size'], 'mime_type' => $spec['mime'],
                'size_bytes' => strlen($this->bytes[$name]), 'sha256' => hash('sha256', $this->bytes[$name]),
            ];
        }
        return $result;
    }
}
