<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use PDO;
use RuntimeException;

final readonly class IconRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function currentRevision(bool $lock = false): ?string
    {
        $statement = $this->pdo->query('SELECT application_icon_revision FROM system_settings WHERE id = 1'
            . ($lock ? ' FOR UPDATE' : ''));
        if ($statement === false) {
            throw new RuntimeException('Unable to read installation icon settings.');
        }
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /** @return array{generation:IconGeneration,updated_at:string,updated_by:array{id:int,username:string}|null}|null */
    public function find(string $revision): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT i.*, u.username FROM installation_icons i
LEFT JOIN users u ON u.id = i.updated_by WHERE i.revision = :revision
SQL);
        $statement->execute(['revision' => $revision]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $bytes = [];
        foreach (IconGeneration::VARIANTS as $variant => $_) {
            $value = $row[$variant];
            $bytes[$variant] = is_resource($value) ? stream_get_contents($value) : $value;
            if (!is_string($bytes[$variant])) {
                throw new RuntimeException('Invalid installation icon bytes.');
            }
        }
        $generation = new IconGeneration($bytes, (string) $row['background'], (int) $row['source_width'],
            (int) $row['source_height'], (float) $row['maskable_scale']);
        if (!hash_equals($revision, $generation->revision)) {
            throw new RuntimeException('Installation icon integrity check failed.');
        }
        return ['generation' => $generation, 'updated_at' => (string) $row['updated_at'],
            'updated_by' => $row['updated_by'] === null ? null
                : ['id' => (int) $row['updated_by'], 'username' => (string) ($row['username'] ?? 'Deleted account')]];
    }

    public function insert(IconGeneration $generation, int $actorId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO installation_icons
(revision,background,source_width,source_height,maskable_scale,favicon,apple_touch,icon_192,icon_512,maskable,ico,updated_by)
VALUES (:revision,:background,:source_width,:source_height,:maskable_scale,:favicon,:apple_touch,:icon_192,:icon_512,:maskable,:ico,:updated_by)
ON CONFLICT (revision) DO NOTHING
SQL);
        $statement->bindValue(':revision', $generation->revision);
        $statement->bindValue(':background', $generation->background);
        $statement->bindValue(':source_width', $generation->sourceWidth, PDO::PARAM_INT);
        $statement->bindValue(':source_height', $generation->sourceHeight, PDO::PARAM_INT);
        $statement->bindValue(':maskable_scale', (string) $generation->maskableScale);
        $statement->bindValue(':updated_by', $actorId, PDO::PARAM_INT);
        foreach ($generation->bytes as $variant => $bytes) {
            $statement->bindValue(':' . $variant, $bytes, PDO::PARAM_LOB);
        }
        $statement->execute();
    }

    public function publish(?string $revision, ?string $previous): void
    {
        $statement = $this->pdo->prepare('UPDATE system_settings SET application_icon_revision = :revision WHERE id = 1');
        $statement->bindValue(':revision', $revision, $revision === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
        // The locked singleton serializes all publishers, including reset. Retain at most current + previous.
        $delete = $this->pdo->prepare(<<<'SQL'
DELETE FROM installation_icons
WHERE revision IS DISTINCT FROM CAST(:current AS text) AND revision IS DISTINCT FROM CAST(:previous AS text)
SQL);
        $delete->execute(['current' => $revision, 'previous' => $previous]);
    }
}
