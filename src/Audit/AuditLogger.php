<?php

declare(strict_types=1);
namespace ChitChat\Audit;

use PDO;
use RuntimeException;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function log(
        ?int $actorUserId,
        string $action,
        string $subjectType,
        ?string $subjectId,
        array $metadata,
        string $ipAddress,
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO audit_log (actor_user_id, action, subject_type, subject_id, metadata_json, ip_address)
VALUES (:actor, :action, :subject_type, :subject_id, CAST(:metadata AS jsonb), :ip)
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare audit-log record.');
        }

        $statement->execute([
            'actor' => $actorUserId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'ip' => $ipAddress,
        ]);
    }
}
