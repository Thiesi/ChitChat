<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\NativeAuth\NativeAuthGuard;
use ChitChat\Database;

final class NativeAuthGuardConcurrencyTest extends DatabaseTestCase
{
    public function testAccountGuardStillAllowsConcurrentAuditForeignKeyChecks(): void
    {
        $user = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '192.0.2.1');
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $this->pdo->beginTransaction();
        try {
            (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy($user->id);
            // A normal concurrent MFA audit takes a user FK KEY SHARE. It must not wait
            // for a native request which may itself be waiting for that factor's row.
            (new AuditLogger($other))->log($user->id, 'auth.native_lock_fixture', 'user', (string) $user->id, [], '192.0.2.2');
            self::assertSame(1, (int) $other->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_lock_fixture'")->fetchColumn());
        } finally {
            $this->pdo->rollBack();
        }
    }
    public function testNativeRestorationDoesNotUpgradeItsAccountLockAndBlockAuditForeignKeys(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', 'a very secure password', '192.0.2.1');
        $member = $auth->register('Member', 'another secure password', '192.0.2.2');
        $closure = new \ChitChat\Account\AccountClosureService($this->pdo, $this->config);
        $closure->request($member, '192.0.2.2');
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $this->pdo->beginTransaction();
        try {
            (new NativeAuthGuard($this->pdo, $this->config))->lockAccountPolicy($member->id, true);
            $closure->completeRestoreInTransaction($member->id, '192.0.2.2', nativeHandoff: true);
            (new AuditLogger($other))->log($member->id, 'auth.native_restore_lock_fixture', 'user', (string) $member->id, [], '192.0.2.3');
            self::assertSame(1, (int) $other->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.native_restore_lock_fixture'")->fetchColumn());
        } finally {
            $this->pdo->rollBack();
        }
    }
}
