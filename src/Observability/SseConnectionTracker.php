<?php

declare(strict_types=1);

namespace ChitChat\Observability;

use PDO;
use RuntimeException;

final class SseConnectionTracker
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $leaseSeconds,
    ) {
    }

    public function open(int $userId): string
    {
        $connectionId = self::uuid();
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO sse_connections (connection_id, user_id, lease_expires_at)
VALUES (:connection_id, :user_id, NOW() + make_interval(secs => CAST(:lease_seconds AS integer)))
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare SSE connection registration.');
        }
        $statement->execute([
            'connection_id' => $connectionId,
            'user_id' => $userId,
            'lease_seconds' => $this->leaseSeconds,
        ]);

        return $connectionId;
    }

    public function touch(string $connectionId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE sse_connections
SET last_seen_at = NOW(),
    lease_expires_at = NOW() + make_interval(secs => CAST(:lease_seconds AS integer))
WHERE connection_id = :connection_id
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare SSE connection lease refresh.');
        }
        $statement->execute([
            'connection_id' => $connectionId,
            'lease_seconds' => $this->leaseSeconds,
        ]);
    }

    public function close(string $connectionId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM sse_connections WHERE connection_id = :connection_id');
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare SSE connection removal.');
        }
        $statement->execute(['connection_id' => $connectionId]);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
