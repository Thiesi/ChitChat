<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\InstallationIcon\IconGeneration;
use ChitChat\InstallationIcon\IconPresets;
use ChitChat\InstallationIcon\IconRenderer;
use ChitChat\InstallationIcon\IconRepository;
use ChitChat\InstallationIcon\IconService;
use ChitChat\InstallationIcon\InitialIcon;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Tests\Support\TestImages;
use ChitChat\Upload\IncomingFile;

final class IconPresetTest extends DatabaseTestCase
{
    use TemporaryPaths;
    private AuthenticatedUser $actor;
    private IconService $icons;

    protected function setUp(): void
    {
        parent::setUp();
        if (!IconRenderer::available()) self::markTestSkipped('GD with PNG and WebP is unavailable.');
        $this->actor = (new AuthService($this->pdo, $this->config))->register('Root', 'a very secure password', '127.0.0.1');
        $this->icons = new IconService($this->pdo, $this->config);
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryPaths();
        parent::tearDown();
    }

    public function testCatalogPreviewAndPublicationKeepTheApprovedContractAndDefaultIsAReset(): void
    {
        $default = $this->icons->get($this->actor);
        self::assertSame('default', $default['source']);
        self::assertNull($default['preset_id']);
        self::assertSame(['lantern','lantern-paper','lounge','dusk','ember','rose','voices','spark','initial'], array_column($default['presets'], 'id'));
        foreach ($default['presets'] as $preset) {
            self::assertTrue($preset['available'], 'The approved sources and font must be packaged before shipping: ' . $preset['id']);
            self::assertIsString($preset['thumbnail_url']);
        }
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $preview = $this->icons->choosePreset($this->actor, 'lounge', '127.0.0.1', true);
        self::assertSame('#111E22', $preview['background']);
        self::assertSame(1.0, $preview['maskable_scale']);
        self::assertStringStartsWith('data:image/png;base64,', $preview['preview']['favicon']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM installation_icons')->fetchColumn());
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        $saved = $this->icons->choosePreset($this->actor, 'lounge', '127.0.0.1');
        self::assertSame('preset', $saved['source']);
        self::assertSame('lounge', $saved['preset_id']);
        self::assertSame('#111E22', $saved['background']);
        $same = $this->icons->choosePreset($this->actor, 'lounge', '127.0.0.1');
        self::assertSame($saved['revision'], $same['revision']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='system.application_icon_updated'")->fetchColumn());
        try { $this->icons->update($this->actor, null, '#FFFFFF', '127.0.0.1'); self::fail('A preset background was changed.'); }
        catch (ApiException $error) { self::assertSame(422, $error->status); self::assertSame('preset_background_fixed', $error->errorCode); }
        $preview = $this->icons->choosePreset($this->actor, 'lantern', '127.0.0.1', true);
        self::assertSame($saved['revision'], $this->icons->get($this->actor)['revision']);
        self::assertSame(file_get_contents(dirname(__DIR__, 2) . '/public/assets/app-icons/favicon-32.png'),
            base64_decode(substr($preview['preview']['favicon'], strlen('data:image/png;base64,')), true));
        $reset = $this->icons->choosePreset($this->actor, 'lantern', '127.0.0.1');
        self::assertSame('default', $reset['source']);
        self::assertNull($reset['revision']);
        self::assertNull($reset['preset_id']);
        self::assertNull((new IconRepository($this->pdo))->currentRevision());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM installation_icons')->fetchColumn(), 'Reset never stores a copy of the default.');
    }

    public function testAllPresetRenditionsAreOpaqueFixedBackgroundsWithBoundedRetention(): void
    {
        foreach (['lantern-paper','lounge','dusk','ember','rose','voices','spark','initial'] as $id) {
            $saved = $this->icons->choosePreset($this->actor, $id, '127.0.0.1');
            $row = (new IconRepository($this->pdo))->find($saved['revision']);
            self::assertNotNull($row);
            self::assertSame(1.0, $row['generation']->maskableScale);
            foreach (IconGeneration::VARIANTS as $variant => $spec) {
                if ($variant === 'ico') continue;
                $image = imagecreatefromstring($row['generation']->bytes[$variant]);
                self::assertNotFalse($image);
                self::assertSame($spec['size'], imagesx($image));
                self::assertSame($spec['size'], imagesy($image));
                self::assertSame(0, (imagecolorat($image, 0, 0) >> 24) & 127);
                self::assertSame((int) hexdec(substr($saved['background'], 1)), imagecolorat($image, 0, 0) & 0xffffff);
                imagedestroy($image);
            }
            self::assertLessThanOrEqual(2, (int) $this->pdo->query('SELECT COUNT(*) FROM installation_icons')->fetchColumn());
        }
    }

    public function testSupportedRenameReplacesInitialAndUnsupportedRenameKeepsItWithNoticeAndAudit(): void
    {
        $names = new ApplicationNameService($this->pdo, $this->config);
        $names->update($this->actor, 'Harbor Chat', '127.0.0.1');
        $first = $this->icons->choosePreset($this->actor, 'initial', '127.0.0.1');
        self::assertSame('H', $first['initial']);
        $renamed = $names->update($this->actor, 'Juniper', '127.0.0.1');
        self::assertNull($renamed['icon_notice']);
        $second = $this->icons->get($this->actor);
        self::assertSame('J', $second['initial']);
        self::assertNotSame($first['revision'], $second['revision']);
        self::assertNull((new InitialIcon())->character("\u{4E2D} Chat"));
        $unsupported = $names->update($this->actor, "\u{4E2D} Chat", '127.0.0.1');
        self::assertSame("\u{4E2D} Chat", $names->effective());
        self::assertSame(IconPresets::INITIAL_NOTICE, $unsupported['icon_notice']);
        $kept = $this->icons->get($this->actor);
        self::assertSame($second['revision'], $kept['revision']);
        self::assertSame('J', $kept['initial']);
        self::assertSame(IconPresets::INITIAL_NOTICE, $kept['notice']);
        $catalog = array_column($kept['presets'], null, 'id');
        self::assertFalse($catalog['initial']['available']);
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action='system.application_icon_initial_rerender_skipped'")->fetchColumn());
        $names->update($this->actor, 'Maple', '127.0.0.1');
        self::assertSame('M', $this->icons->get($this->actor)['initial']);
        self::assertNull($this->icons->get($this->actor)['notice']);
    }

    public function testNameAuditFailureRollsBackTheNameAndInitialPublicationTogether(): void
    {
        $names = new ApplicationNameService($this->pdo, $this->config);
        $names->update($this->actor, 'Harbor Chat', '127.0.0.1');
        $first = $this->icons->choosePreset($this->actor, 'initial', '127.0.0.1');
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM installation_icons')->fetchColumn();
        $this->pdo->exec("CREATE FUNCTION pg_temp.reject_initial_name_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.action='system.application_name_updated' THEN RAISE EXCEPTION 'test audit failure'; END IF; RETURN NEW; END $$");
        $this->pdo->exec('CREATE TRIGGER reject_initial_name_audit BEFORE INSERT ON audit_log FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_initial_name_audit()');
        try {
            try { $names->update($this->actor, 'Juniper', '127.0.0.1'); self::fail('Audit failure was ignored.'); }
            catch (\PDOException) {}
        } finally { $this->pdo->exec('DROP TRIGGER reject_initial_name_audit ON audit_log'); }
        self::assertSame('Harbor Chat', $names->effective());
        self::assertSame($first['revision'], $this->icons->get($this->actor)['revision']);
        self::assertSame('H', $this->icons->get($this->actor)['initial']);
        self::assertSame($count, (int) $this->pdo->query('SELECT COUNT(*) FROM installation_icons')->fetchColumn());
    }

    public function testUploadsAndStaticPresetsDoNotFollowRenamesAndUnknownPresetsCannotMutate(): void
    {
        $names = new ApplicationNameService($this->pdo, $this->config);
        $this->icons->choosePreset($this->actor, 'initial', '127.0.0.1');
        $file = IncomingFile::forTesting('upload.png', $this->temporaryFile(TestImages::png(512, 512)));
        $upload = $this->icons->update($this->actor, $file, '#123456', '127.0.0.1');
        self::assertSame('upload', $upload['source']);
        self::assertNull($upload['preset_id']);
        self::assertNull($upload['initial']);
        $names->update($this->actor, 'Juniper', '127.0.0.1');
        self::assertSame($upload['revision'], $this->icons->get($this->actor)['revision']);
        $static = $this->icons->choosePreset($this->actor, 'lounge', '127.0.0.1');
        $names->update($this->actor, 'Maple', '127.0.0.1');
        self::assertSame($static['revision'], $this->icons->get($this->actor)['revision']);
        try { $this->icons->choosePreset($this->actor, '../private', '127.0.0.1'); self::fail('Unknown preset was accepted.'); }
        catch (ApiException $error) { self::assertSame('preset_unknown', $error->errorCode); }
        self::assertSame($static['revision'], $this->icons->get($this->actor)['revision']);
    }
    public function testPreparedInitialCannotPublishAfterAConcurrentRename(): void
    {
        (new ApplicationNameService($this->pdo, $this->config))->update($this->actor, 'Harbor Chat', '127.0.0.1');
        self::assertSame('H', (new InitialIcon())->character('Harbor Chat'));
        $pidFile = $this->temporaryFile();
        $resultFile = $this->temporaryFile();
        $log = $this->temporaryFile();
        $fixture = $this->temporaryFile(json_encode(['user_id' => $this->actor->id,
            'pid_file' => $pidFile, 'result_file' => $resultFile], JSON_THROW_ON_ERROR));
        $other = \ChitChat\Database::connect($this->config);
        $process = null;
        try {
            $other->beginTransaction();
            $other->query('SELECT id FROM system_settings WHERE id=1 FOR UPDATE')->fetchColumn();
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/icon-preset-worker.php', $fixture],
                [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($process);
            fclose($pipes[0]);
            $wait = $this->pdo->prepare("SELECT wait_event_type='Lock' AND query LIKE '%system_settings%' FROM pg_stat_activity WHERE pid=:pid");
            $waiting = false;
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $pid = trim((string) file_get_contents($pidFile));
                if ($pid !== '') {
                    $wait->execute(['pid' => (int) $pid]);
                    if ((bool) $wait->fetchColumn()) { $waiting = true; break; }
                }
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertTrue($waiting, 'The prepared initial must be waiting for the publication lock. ' . file_get_contents($log));
            // Commit the concurrent name while the old-name artwork waits, as the rename transaction would.
            $other->exec("UPDATE system_settings SET application_name='Juniper' WHERE id=1");
            $other->commit();
            $deadline = hrtime(true) + 10_000_000_000;
            do {
                $running = proc_get_status($process)['running'];
                if (!$running) break;
                usleep(50_000);
            } while (hrtime(true) < $deadline);
            self::assertFalse($running, 'Preset worker did not complete. ' . file_get_contents($log));
            self::assertSame(['error' => 'application_icon_changed', 'status' => 409],
                json_decode((string) file_get_contents($resultFile), true, 8, JSON_THROW_ON_ERROR));
            self::assertNull((new IconRepository($this->pdo))->currentRevision());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM installation_icons')->fetchColumn());
            self::assertSame('Juniper', (new ApplicationNameService($this->pdo, $this->config))->effective());
        } finally {
            if ($other->inTransaction()) $other->rollBack();
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) proc_terminate($process);
                proc_close($process);
            }
        }
    }

}
