<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\NativeAuth\NativeAuthConfiguration as NativeConfig;
use ChitChat\Auth\NativeAuth\NativeAuthRepository;
use ChitChat\Auth\NativeAuth\NativeAuthSession;
use ChitChat\Auth\NativeAuth\NativeAuthTransactions;
use ChitChat\Config;
use ChitChat\Http\ApiException;

final class NativeAuthTransactionsTest extends DatabaseTestCase
{
    private Config $nativeConfig;
    private NativeAuthTransactions $native;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->nativeConfig = $this->configWith(['applicationOrigin' => 'https://chat.test',
            'oidcRedirectOrigin' => 'https://chat.test',
            'oidcProviders' => ['google' => ['client_id' => 'client', 'client_secret' => 'secret', 'endpoint_override' => '']],
            'nativeAndroidApps' => [['package' => 'de.networkxxiii.chitchat',
                'sha256_cert_fingerprint' => implode(':', array_fill(0, 32, 'AB'))]]]);
        $this->native = new NativeAuthTransactions($this->pdo, $this->nativeConfig);
    }

    /** @return array<string,mixed> */
    private function payload(): array
    {
        return ['client_id' => 'de.networkxxiii.chitchat', 'provider' => 'google', 'purpose' => 'login',
            'redirect_uri' => 'de.networkxxiii.chitchat:/native-auth/callback', 'state' => NativeConfig::token(),
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'code_challenge_method' => 'S256'];
    }

    public function testStartUsesAbsoluteHttpsOpaqueEntryAndOneActiveIntentWithReadOnlyStatus(): void
    {
        $first = $this->native->start($this->payload(), null, '192.0.2.1');
        self::assertStringStartsWith('https://chat.test/native-auth.php?', $first['browser_url']);
        parse_str((string) parse_url($first['browser_url'], PHP_URL_QUERY), $query);
        self::assertSame(['transaction_id', 'entry'], array_keys($query));
        $row = (new NativeAuthRepository($this->pdo))->require($first['transaction_id']);
        self::assertSame(hash('sha256', $query['entry']), $row['entry_hash']);
        self::assertNotSame($query['entry'], $row['entry_hash']);
        self::assertStringNotContainsString('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', json_encode($row, JSON_THROW_ON_ERROR));
        $status = $this->native->status($first['transaction_id'], '192.0.2.2');
        self::assertSame('entry', $status['phase']);
        self::assertNull($status['outcome']);
        self::assertNull($status['error_code']);
        self::assertSame($status, $this->native->status($first['transaction_id'], '198.51.100.1'));
        $second = $this->native->start($this->payload(), null, '192.0.2.1');
        self::assertSame('superseded', $this->native->status($first['transaction_id'], '192.0.2.1')['error_code']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM native_auth_transactions WHERE active')->fetchColumn());
        self::assertSame(['status' => 'cancelled'], $this->native->cancel($second['transaction_id'], '192.0.2.1'));
        self::assertSame('cancelled', $this->native->status($second['transaction_id'], '192.0.2.1')['error_code']);
        $ownSession = $_SESSION;
        $_SESSION = [];
        NativeAuthSession::nativeHash(true);
        try {
            $this->native->status($second['transaction_id'], '192.0.2.1');
            self::fail('Another app session learned a transaction outcome.');
        } catch (ApiException $exception) {
            self::assertSame('native_session_changed', $exception->errorCode);
        }
        $_SESSION = $ownSession;
        self::assertSame('cancelled', $this->native->status($second['transaction_id'], '192.0.2.1')['error_code']);
    }

    public function testConsumedStatusRecordsOutcomeWithoutReplayingOrAllowingCancel(): void
    {
        $start = $this->native->start($this->payload(), null, '192.0.2.1');
        (new NativeAuthRepository($this->pdo))->consume($start['transaction_id'], 'pending_mfa');
        $before = $this->pdo->query('SELECT phase, outcome, finished_at FROM native_auth_transactions')->fetch();
        $status = $this->native->status($start['transaction_id'], '192.0.2.1');
        self::assertSame('used', $status['phase']);
        self::assertSame('pending_mfa', $status['outcome']);
        self::assertSame('already_used', $status['error_code']);
        self::assertSame($before, $this->pdo->query('SELECT phase, outcome, finished_at FROM native_auth_transactions')->fetch());
        try {
            $this->native->cancel($start['transaction_id'], '192.0.2.1');
            self::fail('Cancellation claimed to undo an exchange.');
        } catch (ApiException $exception) {
            self::assertSame('already_used', $exception->errorCode);
        }
        self::assertSame($before, $this->pdo->query('SELECT phase, outcome, finished_at FROM native_auth_transactions')->fetch());
    }

    public function testCodeExpiryAndConfigurationChangesAreDistinctAndDoNotMutateOnStatusReads(): void
    {
        $start = $this->native->start($this->payload(), null, '192.0.2.1');
        $rows = new NativeAuthRepository($this->pdo);
        $rows->statement("UPDATE native_auth_transactions SET phase = 'staged', code_hash = :hash, code_expires_at = clock_timestamp() - interval '1 second' WHERE id = :id",
            ['hash' => hash('sha256', 'synthetic-code'), 'id' => $start['transaction_id']]);
        self::assertSame('code_expired', $this->native->status($start['transaction_id'], '192.0.2.1')['error_code']);
        self::assertSame('staged', $rows->require($start['transaction_id'])['phase']);
        $start = $this->native->start($this->payload(), null, '192.0.2.1');
        $changed = $this->configWith(['applicationOrigin' => '', 'nativeAndroidApps' => []]);
        $disabled = new NativeAuthTransactions($this->pdo, $changed);
        self::assertSame('native_config_changed', $disabled->status($start['transaction_id'], '192.0.2.1')['error_code']);
        self::assertSame('entry', $rows->require($start['transaction_id'])['phase']);
    }

    public function testExpiryScrubsPrivateProofAndMaintenanceDeletesOldTombstones(): void
    {
        $start = $this->native->start($this->payload(), null, '192.0.2.1');
        $rows = new NativeAuthRepository($this->pdo);
        $rows->stageProof($start['transaction_id'], 'consent', null, null,
            ['subject' => 'private-subject', 'auth_time' => null, 'started_at' => time()], null);
        $rows->statement("UPDATE native_auth_transactions SET created_at = statement_timestamp() - interval '11 minutes', expires_at = statement_timestamp() - interval '1 minute' WHERE id = :id",
            ['id' => $start['transaction_id']]);
        self::assertSame('expired', $this->native->status($start['transaction_id'], '192.0.2.1')['error_code']);
        self::assertSame(1, $rows->cleanup(true)['native_auth_expired']);
        self::assertNotNull($rows->require($start['transaction_id'])['proof']);
        self::assertSame(1, $rows->cleanup(false)['native_auth_expired']);
        $row = $rows->require($start['transaction_id']);
        foreach (['proof', 'picture', 'code_hash', 'entry_hash', 'bootstrap_hash', 'subject_hash', 'return_state'] as $secret) {
            self::assertNull($row[$secret], $secret);
        }
        $rows->statement("UPDATE native_auth_transactions SET finished_at = clock_timestamp() - interval '2 hours' WHERE id = :id", ['id' => $start['transaction_id']]);
        self::assertSame(1, $rows->cleanup(true)['native_auth_tombstones']);
        self::assertSame(1, $rows->cleanup(false)['native_auth_tombstones']);
        self::assertNull($rows->find($start['transaction_id']));
    }

    public function testRedirectMismatchAndPlainPkceAreRejectedBeforeAnyTransactionIsCreated(): void
    {
        foreach (['redirect_uri' => 'https://evil.test', 'code_challenge_method' => 'plain'] as $key => $value) {
            try {
                $this->native->start(array_replace($this->payload(), [$key => $value]), null, '192.0.2.1');
                self::fail('Invalid native start was accepted.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->status);
            }
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM native_auth_transactions')->fetchColumn());
    }
}
