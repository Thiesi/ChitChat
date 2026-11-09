<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Auth\AuthService;
use ChitChat\Http\ApiException;
use ChitChat\Http\PublicSessionMetadata;
use ChitChat\InstallationIcon\IconRepository;
use ChitChat\InstallationIcon\IconRenderer;
use ChitChat\InstallationIcon\IconService;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestImages;
use ChitChat\Upload\IncomingFile;

final class IconServiceTest extends DatabaseTestCase
{
    use TemporaryPaths;

    protected function setUp(): void
    {
        parent::setUp();
        if (!IconRenderer::available()) self::markTestSkipped('GD with PNG and WebP is unavailable.');
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();
        parent::tearDown();
    }

    public function testPreviewHasNoIconOrAuditSideEffectsAndSaveIsAtomicIdempotentAndBounded(): void
    {
        $actor = (new AuthService($this->pdo,$this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $service = new IconService($this->pdo,$this->config);
        $default = $service->get($actor);
        self::assertSame('default', $default['state']);
        self::assertNull($default['revision']);
        self::assertSame('/assets/app-icons/icon-512.png', $default['renditions']['icon_512']['url']);
        self::assertNull((new PublicSessionMetadata($this->pdo,$this->config))->application()['icon']);
        $file = IncomingFile::forTesting('private.png', $this->temporaryFile(TestImages::png(600,600)));
        $audits = $this->pdo->query('SELECT count(*) FROM audit_log')->fetchColumn();
        $preview = $service->update($actor,$file,'#abc','127.0.0.1',true);
        self::assertStringStartsWith('data:image/png;base64,', $preview['preview']['maskable']);
        self::assertSame($audits,$this->pdo->query('SELECT count(*) FROM audit_log')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM installation_icons')->fetchColumn());
        self::assertNull((new IconRepository($this->pdo))->currentRevision());
        $first = $service->update($actor,$file,'#abc','127.0.0.1');
        self::assertSame('custom',$first['state']);
        self::assertSame(600,$first['source_width']);
        self::assertSame($actor->id,$first['updated_by']['id']);
        $same = $service->update($actor,$file,'#abc','127.0.0.1');
        self::assertSame($first['revision'],$same['revision']);
        self::assertSame(1,(int)$this->pdo->query("SELECT count(*) FROM audit_log WHERE action='system.application_icon_updated'")->fetchColumn());
        $second = $service->update($actor,null,'#fff','127.0.0.1');
        self::assertSame(600,$second['source_width']);
        self::assertSame($first['renditions']['icon_512']['sha256'],$second['renditions']['icon_512']['sha256']);
        self::assertNotSame($first['renditions']['maskable']['sha256'],$second['renditions']['maskable']['sha256']);
        $third = $service->update($actor,null,'#111','127.0.0.1');
        $repository = new IconRepository($this->pdo);
        self::assertNull($repository->find($first['revision']));
        self::assertNotNull($repository->find($second['revision']));
        self::assertNotNull($repository->find($third['revision']));
        self::assertSame(2,(int)$this->pdo->query('SELECT count(*) FROM installation_icons')->fetchColumn());
        $public = (new PublicSessionMetadata($this->pdo,$this->config))->application()['icon'];
        self::assertSame($third['renditions']['icon_512']['url'],$public['url']);
        // Selecting a retained immutable revision is a new publication, not its old attribution.
        $this->pdo->exec("UPDATE system_settings SET application_icon_updated_at='2000-01-01'");
        $republished = $service->update($actor,null,'#fff','127.0.0.1');
        self::assertSame($second['revision'],$republished['revision']);
        self::assertStringNotContainsString('2000-01-01',$republished['updated_at']);
        $reset = $service->reset($actor,'127.0.0.1');
        self::assertSame('default',$reset['state']);
        $service->reset($actor,'127.0.0.1');
        self::assertSame(1,(int)$this->pdo->query("SELECT count(*) FROM audit_log WHERE action='system.application_icon_reset'")->fetchColumn());
    }

    public function testFailuresAndAuditRollbackPreservePreviousGeneration(): void
    {
        $auth = new AuthService($this->pdo,$this->config);
        $root = $auth->register('Root','a very secure password','127.0.0.1');
        $member = $auth->register('Member','another secure password','127.0.0.2');
        $service = new IconService($this->pdo,$this->config);
        $file = IncomingFile::forTesting('icon.png',$this->temporaryFile(TestImages::png(512,512)));
        $before = $service->update($root,$file,'#123456','127.0.0.1');
        foreach ([
            fn() => $service->get($member),
            fn() => $service->update($member,$file,'#fff','127.0.0.1',true),
            fn() => $service->update($root,IncomingFile::forTesting('bad.png',$this->temporaryFile('bad')),'#fff','127.0.0.1'),
        ] as $call) {
            try { $call(); self::fail('Invalid update accepted.'); } catch (ApiException) {}
            self::assertSame($before['revision'],$service->get($root)['revision']);
        }
        // A rejected audit write rolls back the inserted generation and pointer together.
        $this->pdo->exec("CREATE FUNCTION pg_temp.reject_icon_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='system.application_icon_updated' THEN RAISE EXCEPTION 'test audit failure'; END IF; RETURN NEW; END $$");
        $this->pdo->exec('CREATE TRIGGER reject_icon_audit BEFORE INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_icon_audit()');
        try {
            try { $service->update($root,null,'#000','127.0.0.1'); self::fail('Audit failure ignored.'); }
            catch (\PDOException) {}
        } finally {
            $this->pdo->exec('DROP TRIGGER reject_icon_audit ON audit_log');
        }
        self::assertSame($before['revision'],$service->get($root)['revision']);
        self::assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM installation_icons')->fetchColumn());
    }

    public function testBackgroundPreparedBeforeConcurrentResetCannotResurrectTheIcon(): void
    {
        $actor = (new AuthService($this->pdo,$this->config))->register('Root','a very secure password','127.0.0.1');
        $service = new IconService($this->pdo,$this->config);
        $file = IncomingFile::forTesting('icon.png',$this->temporaryFile(TestImages::png(512,512)));
        $initial = $service->update($actor,$file,'#14111C','127.0.0.1');
        $pidFile = $this->temporaryFile();
        $resultFile = $this->temporaryFile();
        $log = $this->temporaryFile();
        $fixture = $this->temporaryFile(json_encode(['user_id'=>$actor->id,'pid_file'=>$pidFile,'result_file'=>$resultFile],JSON_THROW_ON_ERROR));
        $other = \ChitChat\Database::connect($this->config);
        $process = null;
        try {
            $other->beginTransaction();
            $other->query('SELECT id FROM system_settings WHERE id=1 FOR UPDATE')->fetchColumn();
            $process = proc_open([PHP_BINARY,dirname(__DIR__).'/Support/icon-background-worker.php',$fixture],
                [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__,2));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $wait = $this->pdo->prepare("SELECT wait_event_type='Lock' AND query LIKE '%system_settings%' FROM pg_stat_activity WHERE pid=:pid");
            $deadline = hrtime(true)+10_000_000_000;
            $waiting = false;
            do {
                $pid = trim((string)file_get_contents($pidFile));
                if ($pid !== '') {
                    $wait->execute(['pid'=>(int)$pid]);
                    $waiting = (bool)$wait->fetchColumn();
                    if ($waiting) break;
                }
                usleep(50_000);
            } while(hrtime(true)<$deadline);
            self::assertTrue($waiting,'Background update must have prepared the old source and be waiting on the settings lock. '.file_get_contents($log));
            // Complete a reset while the background preparation still refers to the old generation.
            (new IconRepository($other))->publish(null,$initial['revision']);
            $other->commit();
            $deadline = hrtime(true)+10_000_000_000;
            do {
                $running = proc_get_status($process)['running'];
                if (!$running) break;
                usleep(50_000);
            } while(hrtime(true)<$deadline);
            self::assertFalse($running,'Background worker did not finish.');
            self::assertSame(['error_code'=>'application_icon_changed','status'=>409],
                json_decode((string)file_get_contents($resultFile),true),file_get_contents($log));
            self::assertNull((new IconRepository($this->pdo))->currentRevision());
            self::assertSame(1,(int)$this->pdo->query('SELECT count(*) FROM installation_icons')->fetchColumn());
        } finally {
            if ($other->inTransaction()) $other->rollBack();
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
    }

    public function testDatabaseRejectsOversizedGenerationsAndServingDetectsTampering(): void
    {
        $actor = (new AuthService($this->pdo,$this->config))->register('Root','a very secure password','127.0.0.1');
        $service = new IconService($this->pdo,$this->config);
        $file = IncomingFile::forTesting('icon.png',$this->temporaryFile(TestImages::png(512,512)));
        $description = $service->update($actor,$file,'#123','127.0.0.1');
        try {
            $this->pdo->exec("UPDATE installation_icons SET ico=decode(repeat('00',4194305),'hex')");
            self::fail('Database accepted an oversized generation.');
        } catch (\PDOException $e) {
            self::assertSame('23514',$e->getCode());
        }
        self::assertSame($description['revision'],$service->get($actor)['revision']);
        $this->pdo->exec("UPDATE installation_icons SET favicon=decode('00','hex')");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');
        (new IconRepository($this->pdo))->find($description['revision']);
    }
}
