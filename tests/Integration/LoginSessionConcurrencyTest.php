<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Database;

final class LoginSessionConcurrencyTest extends DatabaseTestCase
{
    public function testIssuanceKeepsAnOuterTransactionAndAllowsConcurrentAuditForeignKeyChecks(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '500ms'");
        $this->pdo->beginTransaction();
        try {
            (new LoginSessionRepository($this->pdo))->issue($member);
            self::assertTrue($this->pdo->inTransaction());
            (new AuditLogger($other))->log($member->id, 'auth.login_lock_fixture', 'user', (string) $member->id, [], '127.0.0.2');
            self::assertSame(1, (int) $other->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login_lock_fixture'")->fetchColumn());
        } finally {
            $this->pdo->rollBack();
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM login_sessions')->fetchColumn());
    }

    public function testRevocationWaitsAtTheAccountBeforeTakingAnyLoginLock(): void
    {
        $member = (new AuthService($this->pdo, $this->config))->register('Member', 'a very secure password', '127.0.0.1');
        $logins = new LoginSessionRepository($this->pdo);
        $_SESSION = [];
        $one = $logins->issue($member);
        $two = $logins->issue($member);
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '200ms'");
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query('SELECT id FROM users WHERE id = ' . $member->id . ' FOR NO KEY UPDATE');
            try {
                (new LoginSessionRepository($other))->revoke($member, $two->id, hash('sha256', $two->token), $one->id, '127.0.0.1');
                self::fail('Revocation bypassed the account lock.');
            } catch (\PDOException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
            self::assertFalse($other->inTransaction());
            self::assertNotFalse($this->pdo->query("SELECT id FROM login_sessions WHERE id = '" . $one->id . "' FOR UPDATE NOWAIT")->fetchColumn());
        } finally {
            $this->pdo->rollBack();
            $_SESSION = [];
        }
        self::assertSame(1, $logins->revoke($member, $two->id, hash('sha256', $two->token), $one->id, '127.0.0.1'));
    }
}
