<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\RateLimiter;
use ChitChat\Upload\AttachmentUploadLimits;
use ChitChat\Upload\IncomingFile;
use PDO;
use Closure;
use RuntimeException;
use Throwable;

final readonly class IconService
{
    private IconRepository $repository;
    private IconPresets $presets;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->repository = new IconRepository($pdo);
        $this->presets = new IconPresets();
    }

    public static function limits(): AttachmentUploadLimits
    {
        return AttachmentUploadLimits::fromIni(IconGeneration::MAX_BYTES,
            ini_get('upload_max_filesize') ?: '0', ini_get('post_max_size') ?: '0');
    }

    public static function requireAdministrator(AuthenticatedUser $actor): void
    {
        if (!$actor->hasRole('super_admin')) {
            throw new ApiException(403, 'forbidden', 'The installation icon requires Super-Administrator access.');
        }
    }

    /** @return array<string,mixed> */
    public function get(AuthenticatedUser $actor): array
    {
        self::requireAdministrator($actor);
        return $this->describe();
    }

    /** @param IncomingFile|Closure():?IncomingFile|null $file
     * @return array<string,mixed> */
    public function update(AuthenticatedUser $actor, IncomingFile|Closure|null $file, string $background, string $ip, bool $dryRun = false): array
    {
        self::requireAdministrator($actor);
        (new RateLimiter($this->pdo, $this->config->rateLimits))->consume(
            $dryRun ? 'application_icon_preview' : 'application_icon_update', 'user:' . $actor->id);
        $limits = self::limits();
        $limits->requireBodyFits($_SERVER['CONTENT_LENGTH'] ?? null);
        if ($file instanceof Closure) $file = $file();
        $selection = $this->repository->selection();
        $sourceRevision = $selection['revision'];
        if ($file === null && $selection['preset_id'] !== null) {
            throw new ApiException(422, 'preset_background_fixed', 'This preset has its own background. Choose an upload to change the background.');
        }
        $previous = $file === null && $sourceRevision !== null
            ? $this->repository->find($sourceRevision)['generation'] ?? null : null;
        if ($file === null) {
            if ($previous === null) {
                throw new ApiException(400, 'validation_error', 'Choose an icon before changing its background.');
            }
            $bytes = $previous->bytes['icon_512'];
        } else {
            $size = @filesize($file->temporaryPath);
            if ($size === false || $size < 1) {
                throw new ApiException(400, 'validation_error', 'The icon upload is empty.');
            }
            if ($limits->maxBytes === 0 || $size > $limits->maxBytes || $file->reportedSize > $limits->maxBytes) {
                throw new ApiException(413, 'attachment_too_large', 'The icon exceeds the upload limit.', maxBytes: $limits->maxBytes);
            }
            $bytes = file_get_contents($file->temporaryPath, false, null, 0, $limits->maxBytes + 1);
            if (!is_string($bytes) || strlen($bytes) !== $size) {
                throw new ApiException(400, 'validation_error', 'The icon upload could not be read.');
            }
        }
        $generation = (new IconRenderer())->create($bytes, $background, $previous);
        if ($dryRun) return $this->preview($generation);
        $this->mutate($actor, $generation, $ip, $file === null, $sourceRevision, expectedPreset: $selection['preset_id']);
        return $this->describe();
    }

    /** @return array<string,mixed> */
    public function reset(AuthenticatedUser $actor, string $ip): array
    {
        self::requireAdministrator($actor);
        (new RateLimiter($this->pdo, $this->config->rateLimits))->consume('application_icon_update', 'user:' . $actor->id);
        $this->mutate($actor, null, $ip);
        return $this->describe();
    }

    /** JSON preset selection uses the same preview/publication pipeline and budgets as uploads.
     * @return array<string,mixed>
     */
    public function choosePreset(AuthenticatedUser $actor, string $id, string $ip, bool $dryRun = false): array
    {
        self::requireAdministrator($actor);
        (new RateLimiter($this->pdo, $this->config->rateLimits))->consume(
            $dryRun ? 'application_icon_preview' : 'application_icon_update', 'user:' . $actor->id);
        $definition = $this->presets->definition($id);
        if ($id === 'lantern') {
            if ($dryRun) {
                $preview = [];
                foreach (['favicon', 'apple_touch', 'maskable'] as $variant) {
                    $path = dirname(__DIR__, 2) . '/public' . IconGeneration::VARIANTS[$variant]['path'];
                    $png = file_get_contents($path);
                    if (!is_string($png)) throw new RuntimeException('A packaged default icon is missing.');
                    $preview[$variant] = 'data:image/png;base64,' . base64_encode($png);
                }
                return ['preview' => $preview, 'background' => '#14111C', 'maskable_scale' => 1.0];
            }
            $this->mutate($actor, null, $ip);
            return $this->describe();
        }
        $selection = $this->repository->selection();
        $name = $selection['application_name'] ?? $this->config->applicationName;
        $sources = $this->presets->sources($id, $name);
        $generation = (new IconRenderer())->preset($sources['full'], $definition['background'], $sources['compact']);
        if ($dryRun) return $this->preview($generation);
        $this->mutate($actor, $generation, $ip, presetId: $id, initial: $sources['initial'],
            expectedName: $id === 'initial' ? $name : null);
        return $this->describe();
    }

    /** Called only by the rename transaction, which already owns the settings row.
     * An undrawable initial never prevents the installation rename.
     */
    public function renameInitial(AuthenticatedUser $actor, string $name, string $ip): ?string
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Initial refresh belongs to the rename transaction.');
        $old = $this->repository->selection(true);
        if ($old['preset_id'] !== 'initial') return null;
        if ((new InitialIcon())->character($name) === null) {
            (new AuditLogger($this->pdo))->log($actor->id, 'system.application_icon_initial_rerender_skipped',
                'system_settings', '1', ['revision' => $old['revision'], 'reason' => 'unsupported_initial'], $ip);
            return IconPresets::INITIAL_NOTICE;
        }
        $sources = $this->presets->sources('initial', $name);
        $generation = (new IconRenderer())->preset($sources['full'], '#F06A3B', $sources['compact']);
        $this->publishLocked($actor, $generation, $ip, $old, 'initial', $sources['initial']);
        return null;
    }

    /** @return array{preview:array<string,string>,maskable_scale:float,background:string} */
    private function preview(IconGeneration $generation): array
    {
        $images = [];
        foreach (['favicon', 'apple_touch', 'maskable'] as $variant) {
            $images[$variant] = 'data:image/png;base64,' . base64_encode($generation->bytes[$variant]);
        }
        if (array_sum(array_map(strlen(...), $images)) > 2_097_152) {
            throw new ApiException(400, 'validation_error', 'The icon preview exceeds the response limit.');
        }
        return ['preview' => $images, 'maskable_scale' => $generation->maskableScale, 'background' => $generation->background];
    }

    private function mutate(AuthenticatedUser $actor, ?IconGeneration $generation, string $ip, bool $checkSource = false,
        ?string $source = null, ?string $presetId = null, ?string $initial = null, ?string $expectedPreset = null,
        ?string $expectedName = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $old = $this->repository->selection(true);
            if (($checkSource && ($old['revision'] !== $source || $old['preset_id'] !== $expectedPreset))
                || ($expectedName !== null && ($old['application_name'] ?? $this->config->applicationName) !== $expectedName)) {
                throw new ApiException(409, 'application_icon_changed', 'The installation icon or name changed. Refresh and try again.');
            }
            $this->publishLocked($actor, $generation, $ip, $old, $presetId, $initial);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @param array{revision:?string,preset_id:?string,initial:?string,application_name:?string} $old */
    private function publishLocked(AuthenticatedUser $actor, ?IconGeneration $generation, string $ip,
        array $old, ?string $presetId = null, ?string $initial = null): void
    {
        if ($old['revision'] === $generation?->revision && $old['preset_id'] === $presetId && $old['initial'] === $initial) return;
        if ($generation !== null) $this->repository->insert($generation, $actor->id);
        $this->repository->publish($generation?->revision, $old['revision'], $actor->id, $presetId, $initial);
        (new AuditLogger($this->pdo))->log(actorUserId: $actor->id,
            action: $generation === null ? 'system.application_icon_reset' : 'system.application_icon_updated',
            subjectType: 'system_settings', subjectId: '1',
            metadata: ['old_revision' => $old['revision'], 'new_revision' => $generation?->revision,
                'old_preset' => $old['preset_id'], 'new_preset' => $presetId], ipAddress: $ip);
    }

    /** @return array<string,mixed> */
    private function describe(): array
    {
        $selection = $this->repository->selection();
        $revision = $selection['revision'];
        $name = $selection['application_name'] ?? $this->config->applicationName;
        $row = $revision === null ? null : $this->repository->find($revision);
        if ($revision !== null && $row === null) {
            throw new RuntimeException('The current installation icon is missing.');
        }
        $generation = $row['generation'] ?? null;
        $renditions = $generation?->renditions() ?? [];
        if ($generation === null) {
            foreach (IconGeneration::VARIANTS as $variant => $spec) {
                $path = dirname(__DIR__, 2) . '/public' . $spec['path'];
                $bytes = file_get_contents($path);
                if (!is_string($bytes)) {
                    throw new RuntimeException('A packaged installation icon is missing.');
                }
                $renditions[$variant] = ['url' => $spec['path'], 'width' => $spec['size'], 'height' => $spec['size'],
                    'mime_type' => $spec['mime'], 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }
        }
        $limits = self::limits();
        $attribution = $generation === null ? null : $this->repository->attribution();
        return ['state' => $generation === null ? 'default' : 'custom', 'revision' => $revision,
            'source' => $generation === null ? 'default' : ($selection['preset_id'] === null ? 'upload' : 'preset'),
            'preset_id' => $selection['preset_id'], 'initial' => $selection['initial'],
            'presets' => $this->presets->catalog($name),
            'notice' => $selection['preset_id'] === 'initial' && (new InitialIcon())->character($name) === null
                ? IconPresets::INITIAL_NOTICE : null,
            'background' => $generation === null ? '#14111C' : $generation->background,
            'source_width' => $generation?->sourceWidth, 'source_height' => $generation?->sourceHeight,
            'maskable_scale' => $generation === null ? null : $generation->maskableScale,
            'updated_at' => $attribution['updated_at'] ?? null, 'updated_by' => $attribution['updated_by'] ?? null,
            'renditions' => $renditions,
            'upload_policy' => ['enabled' => IconRenderer::available() && $limits->maxBytes > 0,
                'max_bytes' => $limits->maxBytes, 'minimum_side' => 512, 'maximum_side' => 4000,
                'allowed_mime_types' => ['image/png', 'image/webp']]];
    }

    /** @return array{url:string,width:int,height:int,mime_type:string,sha256:string}|null */
    public function publicIcon(): ?array
    {
        // One snapshot avoids a revision being pruned between separate pointer/hash reads.
        $statement = $this->pdo->query('SELECT i.revision, i.icon_sha256 FROM system_settings s LEFT JOIN installation_icons i ON i.revision=s.application_icon_revision WHERE s.id=1');
        $row = $statement === false ? false : $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Installation icon settings are missing.');
        }
        if ($row['revision'] === null) {
            return null;
        }
        return ['url' => '/installation-icon.php?revision=' . $row['revision'] . '&variant=icon_512',
            'width' => 512, 'height' => 512, 'mime_type' => 'image/png', 'sha256' => (string)$row['icon_sha256']];
    }

    /** @return array{favicon_url:string,mark_url:string,apple_touch_url:string}|null */
    public static function resolve(Config $config): ?array
    {
        try {
            $repository = new IconRepository(Database::connect($config));
            $revision = $repository->currentRevision();
            if ($revision === null) {
                return null;
            }
            $url = '/installation-icon.php?revision=' . $revision . '&variant=';
            return ['favicon_url' => $url . 'favicon', 'mark_url' => $url . 'icon_192',
                'apple_touch_url' => $url . 'apple_touch'];
        } catch (Throwable) {
            return null;
        }
    }
}
