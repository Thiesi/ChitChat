<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Account\AvatarService;
use ChitChat\Account\PersonalDataExportService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\ReportService;
use ChitChat\Moderation\UserReportService;
use ChitChat\Policy\PolicyService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Upload\AttachmentFileStore;
use PDO;
use PDOException;

final class UserReportServiceTest extends DatabaseTestCase
{
    // Metadata-free, pre-encoded 256px stored-avatar fixtures, independent of optional GD.
    private const RED = 'UklGRsIAAABXRUJQVlA4ILYAAAAwEQCdASoAAQABPlEokkcjoqGhICgAcAoJaW7hdrEe3AAAE9gHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snCgAD+/2yx//3QZZw02///qDP/IM/8gz9Rl6LD4EAAAAAAAAAAAA==';
    private const BLUE = 'UklGRr4AAABXRUJQVlA4ILIAAAAwEQCdASoAAQABPlEokkcjoqGhICgAcAoJaW7hdrEe3AAAFZu14uTkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ych77ZOQ99snIe+2TkPfbJyHvtk5D32ycfwAD+/2yx///ziX8kfoF3Z//9bXtvDCgAAAAAAAAAAAAA';

    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $path) {
            TestScratch::remove($path);
        }
        parent::tearDown();
    }

    public function testPersonCasesAreSplitByScopeAndKeepMessageWireCompatibility(): void
    {
        [$root, $subject, $reporter, $modA, $modB, $other] = $this->people(6);
        $rooms = new RoomService($this->pdo);
        $a = $rooms->create($root, 'first', 'First room', '', 'public', 0, 0, '127.0.0.1');
        $b = $rooms->create($root, 'second', 'Second room', '', 'public', 0, 0, '127.0.0.1');
        foreach ([[$subject, $a], [$subject, $b], [$modA, $a], [$modB, $b]] as [$person, $room]) {
            $rooms->join($person, $room->id, '127.0.0.1');
        }
        $rooms->setRole($root, $a->id, $modA->id, 'moderator', '127.0.0.1');
        $rooms->setRole($root, $b->id, $modB->id, 'moderator', '127.0.0.1');
        $people = new UserReportService($this->pdo, $this->config);
        $first = $people->report($reporter, $subject->id, 'offensive_username', 'Only room A sees this', $a->id, '127.0.0.1');
        $second = $people->report($reporter, $subject->id, 'harassment', 'Private room B detail', $b->id, '127.0.0.1');
        $global = $people->report($reporter, $subject->id, 'other', 'Global private detail', null, '127.0.0.1');
        self::assertCount(3, array_unique([$first['id'], $second['id'], $global['id']]));
        $people->report($other, $subject->id, 'spam', 'Another room A report', $a->id, '127.0.0.1');
        $this->assertApi(409, 'already_reported', fn () => $people->report($reporter, $subject->id, 'spam', 'duplicate', $a->id, '127.0.0.1'));
        $cases = new ReportService($this->pdo);
        self::assertSame([$first['id']], array_column($cases->cases($modA)['cases'], 'id'));
        self::assertSame([$second['id']], array_column($cases->cases($modB)['cases'], 'id'));
        self::assertCount(3, $cases->cases($root, subjectKind: 'user')['cases']);
        $detail = $cases->caseDetail($modA, $first['id']);
        self::assertSame('user', $detail['subject_kind']);
        self::assertNull($detail['message_id']);
        self::assertNull($detail['message_kind']);
        self::assertSame(['id' => $a->id, 'name' => $a->name, 'available' => true], $detail['room']);
        self::assertSame(2, $detail['report_count']);
        self::assertSame(['username' => $subject->username, 'avatar_url' => null], $detail['snapshot']);
        self::assertFalse($detail['avatar_changed_since_report']);
        foreach ($detail['reports'] as $report) {
            self::assertArrayNotHasKey('evidence_body', $report);
            self::assertArrayNotHasKey('evidence', $report);
        }
        $encoded = json_encode($detail, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private room B detail', $encoded);
        self::assertStringNotContainsString('Global private detail', $encoded);
        $this->assertApi(403, 'forbidden', fn () => $cases->caseDetail($modA, $second['id']));
        $this->assertApi(403, 'forbidden', fn () => $cases->claim($modA, $global['id'], true, '127.0.0.1'));
        $this->assertApi(403, 'forbidden', fn () => $cases->resolve($modA, $second['id'], 'dismissed', 'no_violation', null, '127.0.0.1'));
        self::assertSame(['can_report' => true, 'reported_by_me' => true], $people->profileStatus($reporter, $subject->id, $a->id));
        self::assertFalse($people->profileStatus($subject, $subject->id, null)['can_report']);
        $this->assertApi(403, 'report_not_allowed', fn () => $people->report($subject, $subject->id, 'spam', null, null, '127.0.0.1'));
        $message = (new MessageService($this->pdo))->send($subject, $a->id, 'A separate message report');
        $this->assertApi(400, 'validation_error', fn () => $cases->reportRoomMessage($reporter, $message['id'], 'offensive_username', null, '127.0.0.1'));
        $messageCase = $cases->reportRoomMessage($reporter, $message['id'], 'spam', null, '127.0.0.1');
        $old = $cases->cases($modA, subjectKind: 'message')['cases'];
        self::assertCount(1, $old);
        self::assertSame('message', $old[0]['subject_kind']);
        self::assertSame($message['id'], $old[0]['message_id']);
        self::assertSame(['id' => $a->id, 'name' => $a->name], $old[0]['room']);
        $this->assertApi(400, 'validation_error', fn () => $cases->resolve($modA, $messageCase['id'], 'resolved', 'profile_picture_removed', null, '127.0.0.1'));
        $this->assertApi(400, 'validation_error', fn () => $cases->cases($modA, subjectKind: 'bogus'));
        $this->assertApi(404, 'moderation_avatar_not_found', fn () => $cases->snapshotAvatar($root, $first['id']));
        $this->assertApi(403, 'forbidden', fn () => $cases->snapshotAvatar($modB, $first['id']));
        $audit = (string) $this->pdo->query("SELECT string_agg(metadata_json::text, '') FROM audit_log WHERE action = 'moderation.report_created'")->fetchColumn();
        self::assertStringNotContainsString('Only room A sees this', $audit);
        self::assertStringNotContainsString('Global private detail', $audit);
    }

    public function testVisiblePastMessagesPermitReportsButUnrelatedPrivateAndAgeRestrictedRoomsDoNot(): void
    {
        [$root, $subject, $reporter, $unrelated] = $this->people(4);
        $rooms = new RoomService($this->pdo);
        $public = $rooms->create($root, 'public', 'Public', '', 'public', 0, 120, '127.0.0.1', 'read');
        $private = $rooms->create($root, 'private', 'Private', '', 'private', 0, 0, '127.0.0.1');
        $adult = $rooms->create($root, 'adult', 'Adult', '', 'public', 18, 0, '127.0.0.1');
        $rooms->join($subject, $public->id, '127.0.0.1');
        (new MessageService($this->pdo))->send($subject, $public->id, 'Visible old message');
        $rooms->leave($subject, $public->id, '127.0.0.1');
        $service = new UserReportService($this->pdo, $this->config);
        self::assertTrue($service->profileStatus($reporter, $subject->id, $public->id)['can_report']);
        self::assertGreaterThan(0, $service->report($reporter, $subject->id, 'harassment', null, $public->id, '127.0.0.1')['id']);
        $this->assertApi(403, 'report_not_allowed', fn () => $service->report($reporter, $unrelated->id, 'spam', null, $public->id, '127.0.0.1'));
        $this->assertApi(403, 'report_not_allowed', fn () => $service->report($reporter, $subject->id, 'spam', null, $private->id, '127.0.0.1'));
        $this->pdo->exec("UPDATE users SET birth_date = '1990-01-01' WHERE id = {$subject->id}");
        $rooms->join($subject, $adult->id, '127.0.0.1');
        $this->assertApi(403, 'report_not_allowed', fn () => $service->report($reporter, $subject->id, 'spam', null, $adult->id, '127.0.0.1'));
        $this->pdo->exec("UPDATE users SET birth_date = '1990-01-01' WHERE id = {$reporter->id}");
        self::assertGreaterThan(0, $service->report($reporter, $subject->id, 'spam', null, $adult->id, '127.0.0.1')['id']);
    }

    public function testGuestsRequireReadableLinkedRoomContextAndPresenceMustBeCurrent(): void
    {
        [$root, $subject, $unrelated] = $this->people(3);
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE');
        $guest = (new GuestService($this->pdo))->start('192.0.2.20');
        $rooms = new RoomService($this->pdo);
        $public = $rooms->create($root, 'guest', 'Guest room', '', 'public', 0, 120, '127.0.0.1', 'read');
        $private = $rooms->create($root, 'private', 'Private room', '', 'private', 0, 0, '127.0.0.1');
        $rooms->join($subject, $public->id, '127.0.0.1');
        (new MessageService($this->pdo))->send($subject, $public->id, 'Visible guest history');
        $rooms->leave($subject, $public->id, '127.0.0.1');
        $service = new UserReportService($this->pdo, $this->config);
        $this->assertApi(403, 'report_not_allowed', fn () => $service->report($guest, $subject->id, 'spam', null, null, '192.0.2.20'));
        $this->assertApi(403, 'report_not_allowed', fn () => $service->report($guest, $subject->id, 'spam', null, $private->id, '192.0.2.20'));
        self::assertGreaterThan(0, $service->report($guest, $subject->id, 'harassment', null, $public->id, '192.0.2.20')['id']);
        $this->pdo->exec("INSERT INTO room_presence (connection_id, user_id, room_id, lease_expires_at) VALUES ('11111111-1111-4111-8111-111111111111', {$unrelated->id}, {$public->id}, NOW() + INTERVAL '1 minute')");
        self::assertTrue($service->profileStatus($guest, $unrelated->id, $public->id)['can_report']);
        $this->pdo->exec("UPDATE room_presence SET last_interaction_at = NOW() - INTERVAL '3 minutes'");
        self::assertFalse($service->profileStatus($guest, $unrelated->id, $public->id)['can_report']);
        $this->pdo->exec("UPDATE room_presence SET last_interaction_at = NOW(), lease_expires_at = NOW() - INTERVAL '1 second'");
        $this->assertApi(403, 'report_not_allowed', fn () => $service->report($guest, $unrelated->id, 'spam', null, $public->id, '192.0.2.20'));
    }

    public function testSnapshotsSurviveAvatarChangesClosureAndFollowCaseAuditRetention(): void
    {
        [$root, $subject, $reporter, $other] = $this->people(4);
        $config = $this->storageConfig('person-report-avatars');
        $bytes = base64_decode(self::RED, true);
        self::assertIsString($bytes);
        $dimensions = getimagesizefromstring($bytes);
        self::assertIsArray($dimensions);
        self::assertSame([256, 256], array_slice($dimensions, 0, 2));
        $files = new AttachmentFileStore($config);
        $key = $this->avatar($files, $subject->id, $bytes);
        $service = new UserReportService($this->pdo, $config);
        $case = $service->report($reporter, $subject->id, 'offensive_profile_picture', 'First private detail', null, '127.0.0.1');
        $cases = new ReportService($this->pdo);
        self::assertSame($bytes, $cases->snapshotAvatar($root, $case['id']));
        $this->assertApi(403, 'forbidden', fn () => $cases->snapshotAvatar($reporter, $case['id']));
        (new AvatarService($this->pdo, $config))->remove($root, $subject->id, '127.0.0.1');
        self::assertFileDoesNotExist($files->pathForKey($key));
        $this->pdo->exec("UPDATE users SET username = 'Changed' WHERE id = {$subject->id}");
        $this->avatar($files, $subject->id, (string) base64_decode(self::BLUE, true));
        $service->report($other, $subject->id, 'offensive_profile_picture', 'Other private detail', null, '127.0.0.1');
        $detail = $cases->caseDetail($root, $case['id']);
        self::assertSame($subject->username, $detail['snapshot']['username']);
        self::assertSame('Changed', $detail['subject']['username']);
        self::assertTrue($detail['avatar_changed_since_report']);
        self::assertSame($bytes, $cases->snapshotAvatar($root, $case['id']));
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM moderation_user_snapshots')->fetchColumn());
        $export = (new PersonalDataExportService($this->pdo, $config))->export($reporter, '127.0.0.1');
        $own = $export['moderation']['reports_submitted'][0];
        self::assertSame('user', $own['subject_kind']);
        self::assertNull($own['message_kind']);
        self::assertNull($own['message_id']);
        $encoded = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($key, $encoded);
        self::assertStringNotContainsString(self::RED, $encoded);
        self::assertStringNotContainsString('Other private detail', $encoded);
        $closure = new AccountClosureService($this->pdo, $config);
        $closure->request($subject, '127.0.0.1');
        $this->pdo->exec("UPDATE users SET closure_requested_at = NOW() - INTERVAL '2 days', closure_finalizes_at = NOW() - INTERVAL '1 day' WHERE id = {$subject->id}");
        $this->pdo->exec("UPDATE account_closures SET requested_at = NOW() - INTERVAL '2 days', finalizes_at = NOW() - INTERVAL '1 day' WHERE user_id = {$subject->id}");
        self::assertSame(1, $closure->finalizeDue());
        self::assertSame($bytes, $cases->snapshotAvatar($root, $case['id']));
        $this->assertApi(404, 'user_not_found', fn () => $service->report($other, $subject->id, 'spam', null, null, '127.0.0.1'));
        $cases->claim($root, $case['id'], true, '127.0.0.1');
        $cases->resolve($root, $case['id'], 'resolved', 'profile_picture_removed', null, '127.0.0.1');
        $auditId = (int) $this->pdo->query('SELECT closed_audit_id FROM moderation_cases WHERE id = ' . $case['id'])->fetchColumn();
        self::assertGreaterThan(0, $auditId);
        $this->pdo->exec('DELETE FROM audit_log WHERE id = ' . $auditId);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM moderation_user_snapshots')->fetchColumn());
        $this->assertApi(404, 'moderation_case_not_found', fn () => $cases->snapshotAvatar($root, $case['id']));
    }

    public function testRoomPurgeKeepsHistoricalScopeAndNeverMakesItGlobal(): void
    {
        [$root, $subject, $reporter, $moderator] = $this->people(4);
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'historical', 'Historical room', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($subject, $room->id, '127.0.0.1');
        $rooms->join($moderator, $room->id, '127.0.0.1');
        $rooms->setRole($root, $room->id, $moderator->id, 'moderator', '127.0.0.1');
        $service = new UserReportService($this->pdo, $this->config);
        $case = $service->report($reporter, $subject->id, 'spam', null, $room->id, '127.0.0.1');
        $this->pdo->exec('DELETE FROM rooms WHERE id = ' . $room->id);
        $detail = (new ReportService($this->pdo))->caseDetail($root, $case['id']);
        self::assertSame(['id' => $room->id, 'name' => $room->name, 'available' => false], $detail['room']);
        self::assertSame('room:' . $room->id, $this->pdo->query('SELECT scope_key FROM moderation_cases WHERE id = ' . $case['id'])->fetchColumn());
        $this->assertApi(403, 'forbidden', fn () => (new ReportService($this->pdo))->caseDetail($moderator, $case['id']));
        $global = $service->report($reporter, $subject->id, 'spam', null, null, '127.0.0.1');
        self::assertNotSame($case['id'], $global['id']);
        self::assertNull((new ReportService($this->pdo))->caseDetail($root, $global['id'])['room']);
    }

    public function testMissingOversizedAndInvalidSubmissionsAreAtomicAndBounded(): void
    {
        [$root, $subject, $reporter] = $this->people(3);
        $config = $this->storageConfig('person-report-limits');
        $service = new UserReportService($this->pdo, $config);
        $this->assertApi(400, 'validation_error', fn () => $service->report($reporter, $subject->id, 'offensive_profile_picture', null, null, '127.0.0.1'));
        $this->assertApi(400, 'validation_error', fn () => $service->report($reporter, $subject->id, 'invalid', null, null, '127.0.0.1'));
        $this->assertApi(400, 'validation_error', fn () => $service->report($reporter, $subject->id, 'spam', str_repeat('é', 1001), null, '127.0.0.1'));
        $files = new AttachmentFileStore($config);
        $key = $this->avatar($files, $subject->id, str_repeat('x', UserReportService::MAX_SNAPSHOT_BYTES + 1));
        $this->assertApi(409, 'report_snapshot_unavailable', fn () => $service->report($reporter, $subject->id, 'spam', null, null, '127.0.0.1'));
        $files->remove($key);
        $this->assertApi(409, 'report_snapshot_unavailable', fn () => $service->report($reporter, $subject->id, 'spam', null, null, '127.0.0.1'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM moderation_cases')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM moderation_reports')->fetchColumn());
        $this->avatar($files, $subject->id, (string) base64_decode(self::RED, true));
        $case = $service->report($reporter, $subject->id, 'spam', str_repeat('é', 1000), null, '127.0.0.1');
        $statement = $this->pdo->prepare('UPDATE moderation_user_snapshots SET avatar_image = :image WHERE case_id = :id');
        self::assertNotFalse($statement);
        $statement->bindValue(':image', str_repeat('x', UserReportService::MAX_SNAPSHOT_BYTES + 1), PDO::PARAM_LOB);
        $statement->bindValue(':id', $case['id'], PDO::PARAM_INT);
        try {
            $statement->execute();
            self::fail('Expected the database snapshot-byte cap.');
        } catch (PDOException $exception) {
            self::assertSame('23514', $exception->getCode());
        }
        self::assertSame((string) base64_decode(self::RED, true), (new ReportService($this->pdo))->snapshotAvatar($root, $case['id']));
    }

    public function testReportingWaitsForTheAvatarRowLockAndRollsBackOnTimeout(): void
    {
        [, $subject, $reporter] = $this->people(3);
        $other = Database::connect($this->config);
        $other->beginTransaction();
        $other->query('SELECT id FROM users WHERE id = ' . $subject->id . ' FOR UPDATE');
        try {
            $this->pdo->exec("SET lock_timeout = '100ms'");
            try {
                (new UserReportService($this->pdo, $this->config))->report($reporter, $subject->id, 'spam', null, null, '127.0.0.1');
                self::fail('Expected report creation to wait for the avatar/account lock.');
            } catch (PDOException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM moderation_cases')->fetchColumn());
        } finally {
            $other->rollBack();
            $this->pdo->exec('SET lock_timeout = 0');
        }
        self::assertGreaterThan(0, (new UserReportService($this->pdo, $this->config))->report($reporter, $subject->id, 'spam', null, null, '127.0.0.1')['id']);
    }

    public function testSnapshotLockAllowsConcurrentForeignKeyChecks(): void
    {
        [, $subject, $reporter] = $this->people(3);
        $other = Database::connect($this->config);
        $other->beginTransaction();
        // Simulates another report/audit FK check on this user while its transaction stays open.
        $other->query('SELECT id FROM users WHERE id = ' . $subject->id . ' FOR KEY SHARE');
        try {
            $this->pdo->exec("SET lock_timeout = '100ms'");
            $case = (new UserReportService($this->pdo, $this->config))->report(
                $reporter, $subject->id, 'spam', null, null, '127.0.0.1',
            );
            self::assertGreaterThan(0, $case['id']);
        } finally {
            $other->rollBack();
            $this->pdo->exec('SET lock_timeout = 0');
        }
    }

    public function testReportsRemainAvailableWhileRulesAcceptanceIsRequired(): void
    {
        [$root, $subject, $reporter] = $this->people(3);
        (new PolicyService($this->pdo, $this->config))->update($root, 'rules', 'hosted', 'New rules', null, false, null, '127.0.0.1');
        self::assertTrue((new PolicyService($this->pdo))->rulesStatus($reporter->id)['acceptance_required']);
        self::assertGreaterThan(0, (new UserReportService($this->pdo, $this->config))->report($reporter, $subject->id, 'spam', null, null, '127.0.0.1')['id']);
    }

    public function testNewReporterReopensTheSameCaseWithoutReplacingTheFirstSnapshot(): void
    {
        [$root, $subject, $first, $second] = $this->people(4);
        $service = new UserReportService($this->pdo, $this->config);
        $cases = new ReportService($this->pdo);
        $case = $service->report($first, $subject->id, 'offensive_username', null, null, '127.0.0.1');
        $cases->resolve($root, $case['id'], 'dismissed', 'no_violation', null, '127.0.0.1');
        $oldAudit = (int) $this->pdo->query('SELECT closed_audit_id FROM moderation_cases WHERE id = ' . $case['id'])->fetchColumn();
        $this->assertApi(409, 'already_reported', fn () => $service->report($first, $subject->id, 'spam', null, null, '127.0.0.1'));
        self::assertSame('dismissed', $cases->caseDetail($root, $case['id'])['status']);
        self::assertTrue($service->profileStatus($first, $subject->id, null)['reported_by_me']);
        self::assertFalse($service->profileStatus($second, $subject->id, null)['reported_by_me']);
        $this->pdo->exec("UPDATE users SET username = 'Changed' WHERE id = {$subject->id}");
        $new = $service->report($second, $subject->id, 'offensive_username', null, null, '127.0.0.1');
        self::assertSame($case['id'], $new['id']);
        self::assertSame('open', $new['status']);
        self::assertSame(2, $new['report_count']);
        self::assertSame($subject->username, $cases->caseDetail($root, $case['id'])['snapshot']['username']);
        $this->pdo->exec('DELETE FROM audit_log WHERE id = ' . $oldAudit);
        self::assertSame('open', $cases->caseDetail($root, $case['id'])['status']);
        $cases->resolve($root, $case['id'], 'resolved', 'user_warned', null, '127.0.0.1');
        self::assertTrue($service->profileStatus($first, $subject->id, null)['reported_by_me']);
        self::assertTrue($service->profileStatus($second, $subject->id, null)['reported_by_me']);
    }

    private function storageConfig(string $label): Config
    {
        $path = TestScratch::directory($label);
        $this->directories[] = $path;
        return $this->configWith(['attachmentStoragePath' => $path]);
    }

    /** @return list<AuthenticatedUser> */
    private function people(int $count): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $people = [];
        for ($index = 0; $index < $count; $index++) {
            $people[] = $auth->register('Person' . $index, 'a very secure password', '127.0.0.' . ($index + 1));
        }
        return $people;
    }

    private function avatar(AttachmentFileStore $files, int $userId, string $bytes): string
    {
        $key = $files->storeBytes($bytes);
        $statement = $this->pdo->prepare('UPDATE users SET avatar_key = :key, avatar_updated_at = NOW() WHERE id = :id');
        self::assertNotFalse($statement);
        $statement->execute(['key' => $key, 'id' => $userId]);
        return $key;
    }

    private function assertApi(int $status, string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected API error ' . $code);
        } catch (ApiException $exception) {
            self::assertSame($status, $exception->status);
            self::assertSame($code, $exception->errorCode);
        }
    }
}
