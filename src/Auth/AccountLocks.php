<?php

declare(strict_types=1);

namespace ChitChat\Auth;

use PDO;
use RuntimeException;

/**
 * Account-row locks for staff actions that change a key of another account (#248).
 *
 * Every audit, event, case or notice row that references the acting staff
 * member takes an FK FOR KEY SHARE on the actor's row. That conflicts only
 * with FOR UPDATE and key-changing updates, so the rule is:
 *
 * - Only a key change (username_canonical, closure tombstoning) takes FOR
 *   UPDATE on a users row. A staff action that does so locks the target FOR
 *   UPDATE and the actor FOR KEY SHARE through forKeyChange(), in numeric ID
 *   order, so two such actions on each other serialize instead of deadlocking.
 * - Every other row lock that intends to update a users row uses FOR NO KEY
 *   UPDATE, which never blocks the actor's FK KEY SHARE.
 *
 * See docs/architecture/0007-account-row-locks.md.
 */
final class AccountLocks
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Locks $targetId FOR UPDATE and $actorId FOR KEY SHARE, lower ID first.
     * Call before any other lock in the transaction that touches either row.
     */
    public function forKeyChange(int $actorId, int $targetId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Account key-change locks require a transaction.');
        }
        if ($actorId === $targetId) {
            $this->lock($targetId, 'FOR UPDATE');
            return;
        }
        if ($actorId < $targetId) {
            $this->lock($actorId, 'FOR KEY SHARE');
            $this->lock($targetId, 'FOR UPDATE');
        } else {
            $this->lock($targetId, 'FOR UPDATE');
            $this->lock($actorId, 'FOR KEY SHARE');
        }
    }

    private function lock(int $userId, string $mode): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM users WHERE id = :id ' . $mode);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare account lock.');
        }
        $statement->execute(['id' => $userId]);
    }
}
