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
}
