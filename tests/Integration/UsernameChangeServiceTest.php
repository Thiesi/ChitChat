<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Account\AvatarService;
use ChitChat\Account\PersonalDataExportService;
use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Account\UsernameChangeService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\PasswordResetService;
use ChitChat\Auth\Username;
use ChitChat\Auth\UserRepository;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Http\PublicSessionMetadata;
use ChitChat\Maintenance\CleanupService;
use ChitChat\Moderation\ReportService;
use ChitChat\Moderation\UserReportService;
use ChitChat\Policy\PolicyService;
use ChitChat\Reactions\DirectMessageReactionService;
use ChitChat\Reactions\RoomReactionService;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\TemporaryPaths;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\IncomingFile;

final class UsernameChangeServiceTest extends DatabaseTestCase
{
    use TemporaryPaths;

    private const PASSWORD = 'Username-Change-Test-Aa1!';
    private const REASON = 'Your username was reported and found to break the community rules.';

    public function testRequiringANewUsernameReplacesTheNameAtOnceEverywhereItIsShown(): void
    {
        [$root, $moderator, $member] = $this->people();
        $room = (new RoomService($this->pdo))->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        (new RoomService($this->pdo))->join($member, $room->id, '127.0.0.3');
        $sent = (new MessageService($this->pdo))->send($member, $room->id, 'Hello @Root');
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM account_notifications WHERE kind = 'mentioned' AND context_json->>'sender_username' = 'Rude.Name'"));
        $eventsBefore = $this->scalar('SELECT COALESCE(MAX(id), 0) FROM realtime_events');

        $result = (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, '  ' . self::REASON . ' ', null, '127.0.0.2');

        $placeholder = $result['user']['username'];
        self::assertTrue(Username::isPlaceholder($placeholder), $placeholder);
        self::assertSame(['id' => $member->id, 'username' => $placeholder], $result['user']);
        self::assertNull($result['case']);
        $row = $this->row("SELECT username, username_canonical, username_change_reason, username_change_previous_canonical, (username_change_required_at IS NOT NULL)::int AS pending FROM users WHERE id = {$member->id}");
        self::assertSame([$placeholder, $placeholder, self::REASON, 'rude.name', 1],
            [$row['username'], $row['username_canonical'], $row['username_change_reason'], $row['username_change_previous_canonical'], (int) $row['pending']]);
        // Held while pending, whatever the configured period.
        self::assertSame([['rude.name', $member->id, null]], $this->rows('SELECT username_canonical, user_id, reserved_until FROM username_reservations'));

        // Existing content joins the live name; the stored mention notice was rewritten too.
        $usernames = array_column((new MessageService($this->pdo))->history($root, $room->id), 'username', 'id');
        self::assertSame($placeholder, $usernames[$sent['id']]);
        $mention = $this->row("SELECT context_json::text AS context FROM account_notifications WHERE kind = 'mentioned'");
        $context = json_decode((string) $mention['context'], true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($placeholder, $context['sender_username']);
        self::assertSame($member->id, $context['sender_user_id']);
        // A push rendered from it now, or retried later, names the placeholder.
        $push = (new PrivacyNotificationService($this->pdo))->renderText('mentioned', $context);
        self::assertStringContainsString($placeholder, $push['title'] . ' ' . $push['message']);

        // The person is told why and what to do.
        $notice = $this->row("SELECT context_json::text AS context FROM account_notifications WHERE user_id = {$member->id} AND kind = 'username_change_required'");
        $rendered = (new PrivacyNotificationService($this->pdo))->renderText('username_change_required', json_decode((string) $notice['context'], true, 8, JSON_THROW_ON_ERROR));
        self::assertSame('Choose a new username', $rendered['title']);
        self::assertStringContainsString(self::REASON, $rendered['message']);
        self::assertSame('/choose-username.php', $rendered['link']);

        $audit = $this->row("SELECT actor_user_id, subject_id, metadata_json::text AS metadata FROM audit_log WHERE action = 'moderation.username_change_required'");
        self::assertSame($moderator->id, (int) $audit['actor_user_id']);
        self::assertSame((string) $member->id, $audit['subject_id']);
        self::assertEquals(['case_id' => null, 'reason' => self::REASON, 'placeholder' => $placeholder, 'old_username' => 'Rude.Name'],
            json_decode((string) $audit['metadata'], true, 8, JSON_THROW_ON_ERROR));

        // One untargeted, roomless event that every connection receives.
        $renames = array_values(array_filter((new EventRepository($this->pdo))->visibleAfter($root, $eventsBefore), static fn ($event): bool => $event->type === 'user_renamed'));
        self::assertCount(1, $renames);
        self::assertSame(['user' => ['id' => $member->id, 'username' => $placeholder]], $renames[0]->payload);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM realtime_events WHERE event_type = 'user_renamed' AND target_user_id IS NULL AND room_id IS NULL"));

        $session = $this->reload($member)->toSessionArray();
        self::assertSame($placeholder, $session['username']);
        self::assertSame(self::REASON, $session['username_change']['reason'] ?? null);
        self::assertTrue($session['username_change']['required'] ?? false);
        self::assertTrue($session['username_change']['sign_in_with_previous_username'] ?? false);
        self::assertMatchesRegularExpression('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00\z/', (string) ($session['username_change']['required_at'] ?? ''));
        self::assertNull($this->reload($root)->toSessionArray()['username_change']);
        self::assertTrue((new PublicSessionMetadata($this->pdo, $this->config))->capabilities()['moderated_username_change']);
    }

    public function testGatedWritesAreRefusedUntilANewNameIsChosenWhileOtherControlsStayOpen(): void
    {
        [$root, $moderator, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.3');
        $messages = new MessageService($this->pdo);
        $own = $messages->send($member, $room->id, 'Before');
        $other = $messages->send($root, $room->id, 'Root speaks');
        $direct = new DirectMessageService($this->pdo);
        $ownDirect = $direct->send($member, $root->id, 'Direct before');
        $rootDirect = $direct->send($root, $member->id, 'Direct reply');
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2');
        $member = $this->reload($member);

        $this->assertError('username_change_required', 403, fn () => $messages->send($member, $room->id, 'After'));
        $this->assertError('username_change_required', 403, fn () => (new RoomMessageMutationService($this->pdo))->edit($member, $own['id'], 'Edited', '127.0.0.3'));
        $this->assertError('username_change_required', 403, fn () => (new DirectMessageService($this->pdo))->send($member, $root->id, 'Hi'));
        $this->assertError('username_change_required', 403, fn () => (new RoomReactionService($this->pdo))->add($member, $other['id'], "\u{1F44D}"));
        $this->assertError('username_change_required', 403, fn () => (new PingService($this->pdo))->send($member, $room->id, 'Root'));
        $this->assertError('username_change_required', 403, fn () => (new AvatarService($this->pdo, $this->config))->upload($member, $this->image(), '127.0.0.3'));
        $this->assertError('username_change_required', 403, fn () => (new AttachmentService($this->pdo, $this->config))->upload($member, $room->id, $this->image(), '', '127.0.0.3'));
        $this->assertError('username_change_required', 403, fn () => (new DirectMessageAttachmentService($this->pdo, $this->config))->upload($member, $root->id, $this->image(), '', '127.0.0.3'));
        $this->assertError('username_change_required', 403, fn () => (new DirectMessageMutationService($this->pdo))->edit($member, $ownDirect['id'], 'Edited', '127.0.0.3'));
        $this->assertError('username_change_required', 403, fn () => (new DirectMessageReactionService($this->pdo))->add($member, $rootDirect['id'], "\u{1F44D}"));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM attachments') + $this->scalar('SELECT COUNT(*) FROM direct_message_attachments'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM room_messages WHERE sender_id = {$member->id}"));

        // Reading, reporting, removing one's own content and leaving stay available.
        self::assertNotEmpty($messages->history($member, $room->id));
        (new UserReportService($this->pdo, $this->config))->report($member, $root->id, 'other', null, null, '127.0.0.3');
        (new RoomMessageMutationService($this->pdo))->deleteOwn($member, $own['id'], '127.0.0.3');

        // Rules acceptance keeps its precedence; accepting it reveals the username requirement.
        $rules = new PolicyService($this->pdo, $this->config);
        $rules->update($root, 'rules', 'hosted', '# Be kind', null, true, 'First rules', '127.0.0.1');
        $this->assertError('rules_acceptance_required', 403, fn () => $messages->send($member, $room->id, 'After'));
        $rules->accept($member, 1, '127.0.0.3');
        $this->assertError('username_change_required', 403, fn () => $messages->send($member, $room->id, 'After'));

        $chosen = (new UsernameChangeService($this->pdo))->choose($member, 'Kind.Name', '127.0.0.3');
        $messages->send($chosen, $room->id, 'Back again');
        (new DirectMessageService($this->pdo))->send($chosen, $root->id, 'Hi');
    }

    public function testChoosingANewNameEndsThePendingChangeAndStartsTheReservationPeriod(): void
    {
        [$root, $moderator, $member] = $this->people();
        $room = (new RoomService($this->pdo))->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        (new RoomService($this->pdo))->join($member, $room->id, '127.0.0.3');
        (new MessageService($this->pdo))->send($member, $room->id, 'Hello @Root');
        $service = new UsernameChangeService($this->pdo);
        $this->assertError('username_change_not_required', 409, fn () => $service->choose($member, 'Other.Name', '127.0.0.3'));
        $placeholder = $service->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2')['user']['username'];
        $member = $this->reload($member);

        $this->assertError('invalid_username', 400, fn () => $service->choose($member, 'no', '127.0.0.3'));
        $this->assertError('username_taken', 409, fn () => $service->choose($member, 'root', '127.0.0.3'));
        $this->assertError('username_taken', 409, fn () => $service->choose($member, 'RUDE.NAME', '127.0.0.3'), 'The removed name is reserved, also for its former holder.');
        $this->assertError('username_taken', 409, fn () => $service->choose($member, 'member-abcdef', '127.0.0.3'));
        $this->assertError('username_taken', 409, fn () => $service->choose($member, $placeholder, '127.0.0.3'));
        $eventsBefore = $this->scalar('SELECT COALESCE(MAX(id), 0) FROM realtime_events');

        $chosen = $service->choose($member, ' Kind.Name ', '127.0.0.3');

        self::assertSame('Kind.Name', $chosen->username);
        self::assertNull($chosen->usernameChange);
        self::assertSame('Kind.Name', $chosen->toSessionArray()['username']);
        $row = $this->row("SELECT username_canonical, username_change_required_at, username_change_reason, username_change_previous_canonical FROM users WHERE id = {$member->id}");
        self::assertSame(['kind.name', null, null, null], array_values($row));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM username_reservations WHERE username_canonical = 'rude.name' AND reserved_until BETWEEN NOW() + interval '364 days' AND NOW() + interval '366 days'"));
        self::assertSame('Kind.Name', $this->row("SELECT context_json->>'sender_username' AS name FROM account_notifications WHERE kind = 'mentioned'")['name']);
        $audit = $this->row("SELECT metadata_json::text AS metadata FROM audit_log WHERE action = 'account.username_changed'");
        self::assertEquals(['to' => 'Kind.Name', 'from' => $placeholder, 'required' => true], json_decode((string) $audit['metadata'], true, 8, JSON_THROW_ON_ERROR));
        $renames = array_values(array_filter((new EventRepository($this->pdo))->visibleAfter($root, $eventsBefore), static fn ($event): bool => $event->type === 'user_renamed'));
        self::assertSame([['user' => ['id' => $member->id, 'username' => 'Kind.Name']]], array_map(static fn ($event): array => $event->payload, $renames));
        $this->assertError('username_change_not_required', 409, fn () => $service->choose($chosen, 'Third.Name', '127.0.0.3'));

        // The reserved name stays unclaimable for everyone until maintenance removes it after expiry.
        $auth = new AuthService($this->pdo, $this->config);
        $this->assertError('username_taken', 409, fn () => $auth->register('rude.NAME', self::PASSWORD, '127.0.0.9'));
        $this->pdo->exec("UPDATE username_reservations SET reserved_until = NOW() - interval '1 second'");
        $cleanup = new CleanupService($this->pdo, $this->config);
        self::assertSame(1, $cleanup->run(true)['expired_username_reservations']);
        self::assertSame(1, $cleanup->run(false)['expired_username_reservations']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM username_reservations'));
        self::assertSame('Rude.Name', $auth->register('Rude.Name', self::PASSWORD, '127.0.0.9')->username);
    }

    public function testWithoutAReservationPeriodTheNameIsHeldOnlyWhileTheChangeIsPending(): void
    {
        [, $moderator, $member] = $this->people();
        $this->pdo->exec('UPDATE system_settings SET username_reservation_days = 0 WHERE id = 1');
        $service = new UsernameChangeService($this->pdo);
        $service->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2');
        $auth = new AuthService($this->pdo, $this->config);
        // Otherwise two accounts would answer to one sign-in name.
        $this->assertError('username_taken', 409, fn () => $auth->register('Rude.Name', self::PASSWORD, '127.0.0.9'));

        $service->choose($this->reload($member), 'Kind.Name', '127.0.0.3');

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM username_reservations'));
        self::assertSame('Rude.Name', $auth->register('Rude.Name', self::PASSWORD, '127.0.0.9')->username);
    }

    public function testRegistrationNeverHandsOutAPlaceholderPatternName(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $this->assertError('username_taken', 409, fn () => $auth->register('Member-ABC234', self::PASSWORD, '127.0.0.2'));
        // Near misses are ordinary names.
        self::assertSame('member-abc1', $auth->register('member-abc1', self::PASSWORD, '127.0.0.2')->username);
        self::assertSame('member-abcdefg', $auth->register('member-abcdefg', self::PASSWORD, '127.0.0.2')->username);
    }

    public function testThePreviousNameSignsInUntilTheNewNameIsChosen(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $moderator = $this->moderator($auth);
        $registered = $auth->registerWithRecoveryKey('Rude.Name', self::PASSWORD, '127.0.0.3');
        $member = $registered['user'];
        $placeholder = (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2')['user']['username'];

        self::assertSame($member->id, $auth->login('rude.name', self::PASSWORD, '127.0.0.3')->id);
        self::assertSame($member->id, $auth->login($placeholder, self::PASSWORD, '127.0.0.3')->id);
        $session = new \ChitChat\Tests\Support\TestSession();
        $session->start();
        try {
            $_SESSION = [];
            $this->pdo->exec('UPDATE system_settings SET registration_proof_of_work_bits = 0 WHERE id = 1');
            $reset = new PasswordResetService($this->pdo, $this->config);
            $nonce = $reset->challenge('127.0.0.3')['challenge']['nonce'];
            self::assertIsArray($reset->begin('Rude.Name', $registered['recovery_key'], 'Another-Strong-Password-9!', $nonce, '0', '127.0.0.3'),
                'Recovery finds the account by its removed name.');
        } finally {
            $_SESSION = [];
            $session->stop();
        }
        $password = 'Another-Strong-Password-9!';

        (new UsernameChangeService($this->pdo))->choose($this->reload($member), 'Kind.Name', '127.0.0.3');

        $this->assertError('invalid_credentials', 401, fn () => $auth->login('rude.name', $password, '127.0.0.3'));
        self::assertSame($member->id, $auth->login('Kind.Name', $password, '127.0.0.3')->id);
    }

    public function testAPendingChangeSurvivesClosureRestorationAndEndsWithFinalization(): void
    {
        [, $moderator, $member] = $this->people();
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2');
        $closures = new AccountClosureService($this->pdo, $this->config);
        $closures->request($this->reload($member), '127.0.0.3');

        // Restoration identifies the account by its removed name too, and the requirement survives.
        $restored = $closures->restore('Rude.Name', self::PASSWORD, '127.0.0.3');
        self::assertSame($member->id, $restored->id);
        self::assertSame(self::REASON, $this->reload($member)->usernameChange['reason'] ?? null);

        $closures->request($this->reload($member), '127.0.0.3');
        $this->pdo->exec("UPDATE account_closures SET requested_at = NOW() - interval '2 days', finalizes_at = NOW() - interval '1 day' WHERE restored_at IS NULL");
        $this->pdo->exec("UPDATE users SET closure_requested_at = NOW() - interval '2 days', closure_finalizes_at = NOW() - interval '1 day' WHERE id = {$member->id}");
        self::assertSame(1, $closures->finalizeDue());

        $row = $this->row("SELECT account_state, username_change_required_at, username_change_previous_canonical FROM users WHERE id = {$member->id}");
        self::assertSame(['closed', null, null], array_values($row));
        // Not freed early by tombstoning: the configured period starts now.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM username_reservations WHERE username_canonical = 'rude.name' AND reserved_until > NOW() + interval '364 days'"));
        $this->assertError('user_not_found', 404, fn () => (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2'));
    }

    public function testOnlyGlobalStaffWhoOutrankTheAccountMayRequireANewName(): void
    {
        [$root, $moderator, $member] = $this->people();
        $auth = new AuthService($this->pdo, $this->config);
        $admin = $auth->register('Admin', self::PASSWORD, '127.0.0.4');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$admin->id}, 'admin')");
        $otherRoot = $auth->register('OtherRoot', self::PASSWORD, '127.0.0.5');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$otherRoot->id}, 'super_admin')");
        $plain = $auth->register('Plain', self::PASSWORD, '127.0.0.6');
        $owner = $auth->register('Owner', self::PASSWORD, '127.0.0.7');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$owner->id}, 'chat_admin')");
        $room = (new RoomService($this->pdo))->create($this->reload($owner), 'den', 'Den', '', 'public', 0, 0, '127.0.0.7');
        $this->pdo->exec("DELETE FROM user_roles WHERE user_id = {$owner->id}");
        $service = new UsernameChangeService($this->pdo);

        $this->assertError('forbidden', 403, fn () => $service->requireChange($this->reload($plain), $member->id, self::REASON, null, '127.0.0.6'));
        $this->assertError('forbidden', 403, fn () => $service->requireChange($this->reload($owner), $member->id, self::REASON, null, '127.0.0.7'), 'A room owner is not global staff.');
        $this->assertError('forbidden', 403, fn () => $service->requireChange($moderator, $this->reload($admin)->id, self::REASON, null, '127.0.0.2'));
        $this->assertError('forbidden', 403, fn () => $service->requireChange($moderator, $moderator->id, self::REASON, null, '127.0.0.2'));
        $this->assertError('validation_error', 400, fn () => $service->requireChange($moderator, $member->id, '   ', null, '127.0.0.2'));
        $this->assertError('validation_error', 400, fn () => $service->requireChange($moderator, $member->id, str_repeat('x', 501), null, '127.0.0.2'));
        $this->assertError('user_not_found', 404, fn () => $service->requireChange($moderator, 999_999, self::REASON, null, '127.0.0.2'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM username_reservations'));

        // Super-Administrators may act on their equals; a repeat is refused without side effects.
        $service->requireChange($root, $otherRoot->id, self::REASON, null, '127.0.0.1');
        $this->assertError('username_change_already_required', 409, fn () => $service->requireChange($root, $otherRoot->id, self::REASON, null, '127.0.0.1'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'moderation.username_change_required'"));

        $this->pdo->exec("UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1");
        $guest = (new \ChitChat\Auth\GuestService($this->pdo))->start('127.0.0.8');
        $this->assertError('validation_error', 400, fn () => $service->requireChange($moderator, $guest->id, self::REASON, null, '127.0.0.2'));
    }

    public function testThePersonCaseThatReportedTheNameIsResolvedInTheSameStep(): void
    {
        [$root, $moderator, $member] = $this->people();
        $reports = new UserReportService($this->pdo, $this->config);
        $case = $reports->report($root, $member->id, 'offensive_username', 'Look at that name', null, '127.0.0.1');
        $otherCase = $reports->report($member, $root->id, 'other', null, null, '127.0.0.3');
        $service = new UsernameChangeService($this->pdo);

        $this->assertError('validation_error', 400, fn () => $service->requireChange($moderator, $member->id, self::REASON, $otherCase['id'], '127.0.0.2'));
        $this->assertError('moderation_case_not_found', 404, fn () => $service->requireChange($moderator, $member->id, self::REASON, 999_999, '127.0.0.2'));
        self::assertSame('Rude.Name', $this->reload($member)->username, 'A refused case rolls the whole action back.');

        $result = $service->requireChange($moderator, $member->id, self::REASON, $case['id'], '127.0.0.2');

        self::assertSame('resolved', $result['case']['status'] ?? null);
        self::assertSame('username_change_required', $result['case']['resolution_code'] ?? null);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM audit_log WHERE action = 'moderation.case_closed' AND metadata_json->>'resolution_code' = 'username_change_required'"));
        self::assertSame($case['id'], (int) $this->row("SELECT metadata_json->>'case_id' AS id FROM audit_log WHERE action = 'moderation.username_change_required'")['id']);
        // The case keeps the reported name as moderator evidence.
        self::assertSame('Rude.Name', $this->row("SELECT username FROM moderation_user_snapshots WHERE case_id = {$case['id']}")['username']);

        // A closed case is refused and nothing changes.
        $service->choose($this->reload($member), 'Kind.Name', '127.0.0.3');
        $this->assertError('moderation_case_closed', 409, fn () => $service->requireChange($moderator, $member->id, self::REASON, $case['id'], '127.0.0.2'));
        self::assertSame('Kind.Name', $this->reload($member)->username);

        // The ordinary resolve accepts the outcome for person cases only.
        $closed = (new ReportService($this->pdo))->resolve($moderator, $otherCase['id'], 'resolved', 'username_change_required', null, '127.0.0.2');
        self::assertSame('username_change_required', $closed['resolution_code']);
        $room = (new RoomService($this->pdo))->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        (new RoomService($this->pdo))->join($member, $room->id, '127.0.0.3');
        $message = (new MessageService($this->pdo))->send($this->reload($member), $room->id, 'Spam');
        $messageCase = (new ReportService($this->pdo))->reportRoomMessage($root, $message['id'], 'spam', null, '127.0.0.1');
        $this->assertError('validation_error', 400, fn () => (new ReportService($this->pdo))->resolve($moderator, $messageCase['id'], 'resolved', 'username_change_required', null, '127.0.0.2'));
        $this->assertError('validation_error', 400, fn () => $service->requireChange($moderator, $member->id, self::REASON, $messageCase['id'], '127.0.0.2'));
    }

    public function testStoredSenderNamesComeFromTheWriteTransactionNotTheRequestStart(): void
    {
        [$root, $moderator, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'lounge', 'Lounge', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.3');
        $service = new UsernameChangeService($this->pdo);
        $service->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2');
        $service->choose($this->reload($member), 'Kind.Name', '127.0.0.3');
        // $member still carries the name loaded before the rename, like a request that started earlier.
        self::assertSame('Rude.Name', $member->username);

        (new MessageService($this->pdo))->send($member, $room->id, 'Hello @Root');
        (new DirectMessageService($this->pdo))->send($member, $root->id, 'Hi @Root');
        $ping = (new PingService($this->pdo))->send($member, $room->id, 'Root');

        self::assertSame(['Kind.Name', 'Kind.Name', 'Kind.Name'], array_map(
            static fn (array $row): string => (string) $row[0],
            $this->pdo->query("SELECT context_json->>'sender_username' FROM account_notifications WHERE kind IN ('mentioned', 'pinged') ORDER BY id")?->fetchAll(\PDO::FETCH_NUM) ?: [],
        ));
        self::assertSame('Kind.Name', $ping['sender']['username']);
    }

    public function testRemovedNameSignInAttemptsAreExportedAndRemovedAtFinalization(): void
    {
        [, $moderator, $member] = $this->people();
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2');
        $auth = new AuthService($this->pdo, $this->config);
        $this->assertError('invalid_credentials', 401, fn () => $auth->login('Rude.Name', 'wrong password', '198.51.100.7'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM login_attempts WHERE username_canonical = 'rude.name'"));

        $export = (new PersonalDataExportService($this->pdo, $this->config))->export($this->reload($member), '127.0.0.3');
        self::assertStringContainsString('198.51.100.7', json_encode($export, JSON_THROW_ON_ERROR), 'The removed-name attempt belongs to this account.');

        $closures = new AccountClosureService($this->pdo, $this->config);
        $closures->request($this->reload($member), '127.0.0.3');
        $this->pdo->exec("UPDATE account_closures SET requested_at = NOW() - interval '2 days', finalizes_at = NOW() - interval '1 day'");
        $this->pdo->exec("UPDATE users SET closure_requested_at = NOW() - interval '2 days', closure_finalizes_at = NOW() - interval '1 day' WHERE id = {$member->id}");
        self::assertSame(1, $closures->finalizeDue());
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM login_attempts WHERE username_canonical = 'rude.name'"));
    }

    public function testThePersonalDataExportShowsAPendingChange(): void
    {
        [, $moderator, $member] = $this->people();
        (new UsernameChangeService($this->pdo))->requireChange($moderator, $member->id, self::REASON, null, '127.0.0.2');
        $export = (new PersonalDataExportService($this->pdo, $this->config))->export($this->reload($member), '127.0.0.3');

        $json = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"previous_username_canonical":"rude.name"', $json);
        self::assertStringContainsString(json_encode(self::REASON, JSON_THROW_ON_ERROR), $json);
    }

    public function testTheNotificationRewriteUsesItsPartialIndex(): void
    {
        $this->pdo->exec('SET enable_seqscan = off');
        try {
            $plan = implode("\n", $this->pdo->query(<<<'SQL'
EXPLAIN UPDATE account_notifications
SET context_json = jsonb_set(context_json, '{sender_username}', to_jsonb('x'::text))
WHERE context_json ?? 'sender_username' AND context_json->'sender_user_id' = to_jsonb(42::bigint)
SQL)?->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } finally {
            $this->pdo->exec('RESET enable_seqscan');
        }
        self::assertStringContainsString('account_notifications_sender', $plan);
    }

    /** @return array{0:AuthenticatedUser, 1:AuthenticatedUser, 2:AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', self::PASSWORD, '127.0.0.1');
        $moderator = $this->moderator($auth);
        $member = $auth->register('Rude.Name', self::PASSWORD, '127.0.0.3');

        return [$root, $moderator, $member];
    }

    private function moderator(AuthService $auth): AuthenticatedUser
    {
        $moderator = $auth->register('Moderator', self::PASSWORD, '127.0.0.2');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");

        return $this->reload($moderator);
    }

    private function reload(AuthenticatedUser $user): AuthenticatedUser
    {
        $reloaded = (new UserRepository($this->pdo))->findAuthenticatedById($user->id);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function image(): IncomingFile
    {
        $image = imagecreatetruecolor(64, 64);
        self::assertNotFalse($image);
        $path = $this->temporaryFile(label: 'avatar');
        imagepng($image, $path);
        imagedestroy($image);

        return IncomingFile::forTesting('avatar.png', $path);
    }

    private function assertError(string $code, int $status, callable $action, string $message = ''): void
    {
        try {
            $action();
            self::fail('Expected ' . $code . '. ' . $message);
        } catch (ApiException $exception) {
            self::assertSame([$status, $code], [$exception->status, $exception->errorCode], $message . ' ' . $exception->getMessage());
        }
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)?->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        $row = $this->pdo->query($sql)?->fetch();
        self::assertIsArray($row, $sql);

        return $row;
    }

    /** @return list<list<mixed>> */
    private function rows(string $sql): array
    {
        $rows = $this->pdo->query($sql)?->fetchAll(\PDO::FETCH_NUM);

        return array_map(static fn (array $row): array => array_map(
            static fn (mixed $value): mixed => is_numeric($value) && (string) (int) $value === (string) $value ? (int) $value : $value,
            $row,
        ), $rows ?: []);
    }
}
