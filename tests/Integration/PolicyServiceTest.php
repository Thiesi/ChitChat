<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Account\AccountClosureService;
use ChitChat\Account\AvatarService;
use ChitChat\Account\DisplayPreferenceService;
use ChitChat\Account\IgnoreService;
use ChitChat\Account\PersonalDataExportService;
use ChitChat\Account\PrivacyNotificationService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\GuestService;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\DirectMessage\DirectMessageAttachmentService;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\MuteService;
use ChitChat\Moderation\ReportService;
use ChitChat\Policy\PolicyService;
use ChitChat\Reactions\DirectMessageReactionService;
use ChitChat\Reactions\RoomReactionService;
use ChitChat\Realtime\PingService;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;
use ChitChat\Tests\Support\TestScratch;
use ChitChat\Upload\AttachmentService;
use ChitChat\Upload\AttachmentFileStore;
use ChitChat\Upload\IncomingFile;
use ChitChat\Tests\Support\PushFixtures;
use PDOException;

final class PolicyServiceTest extends DatabaseTestCase
{
    public function testUnconfiguredPoliciesPreserveRegistrationAndContentBehavior(): void
    {
        [$root, $member] = $this->people();
        $policies = $this->policies();
        self::assertSame(['rules' => null, 'privacy' => null, 'legal_notice' => null], $policies->discovery());
        self::assertNull($policies->publicDocument('rules'));
        self::assertSame(['accepted_version' => 0, 'accepted_at' => null, 'acceptance_required' => false], $member->toSessionArray()['rules']);
        $policies->assertMayWrite($member);
        $room = (new RoomService($this->pdo))->create($root, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        (new RoomService($this->pdo))->join($member, $room->id, '127.0.0.2');
        self::assertSame('Still works', (new MessageService($this->pdo))->send($member, $room->id, 'Still works')['body']);
    }

    public function testVersionsAreMonotonicAndOnlyMaterialRulesChangesRequireAcceptance(): void
    {
        [$root, $member] = $this->people();
        $policies = $this->policies();
        $first = $policies->update($root, 'rules', 'hosted', '# Be kind', null, false, 'First publication', '127.0.0.1');
        self::assertSame(1, $first['version']);
        self::assertTrue($policies->rulesStatus($member->id)['acceptance_required']);
        $accepted = $policies->accept($member, 1, '127.0.0.2');
        self::assertFalse($accepted['acceptance_required']);
        self::assertSame($accepted, $policies->accept($member, 1, '127.0.0.2'));
        self::assertCount(1, $policies->acceptanceHistory($member->id));
        self::assertSame(1, $policies->update($root, 'rules', 'hosted', '# Be kinder', null, false, null, '127.0.0.1')['version']);
        self::assertFalse($policies->rulesStatus($member->id)['acceptance_required']);
        self::assertSame(2, $policies->update($root, 'rules', 'external', null, 'https://127.0.0.1:9/not-fetched', true, 'New rules', '127.0.0.1')['version']);
        $external = $policies->publicDocument('rules');
        self::assertSame('external', $external['source']);
        self::assertNull($external['html']);
        self::assertNull($external['markdown']);
        self::assertSame('https://127.0.0.1:9/not-fetched', $external['url']);
        self::assertTrue($policies->rulesStatus($member->id)['acceptance_required']);
        $this->assertApi(409, 'rules_version_outdated', fn () => $policies->accept($member, 1, '127.0.0.2'));
        $policies->accept($member, 2, '127.0.0.2');
        $policies->update($root, 'rules', 'none', null, null, false, null, '127.0.0.1');
        self::assertNull($policies->discovery()['rules']);
        self::assertFalse($policies->rulesStatus($member->id)['acceptance_required']);
        self::assertSame(3, $policies->update($root, 'rules', 'hosted', '# Re-enabled', null, false, null, '127.0.0.1')['version']);
        self::assertTrue($policies->rulesStatus($member->id)['acceptance_required']);
        self::assertSame([1, 2], array_column($policies->acceptanceHistory($member->id), 'version'));
        $audit = (string) $this->pdo->query("SELECT string_agg(metadata_json::text, '') FROM audit_log WHERE action = 'policy.document_published'")->fetchColumn();
        self::assertStringNotContainsString('Be kind', $audit);
        self::assertStringNotContainsString('First publication', $audit);
    }

    public function testPasswordProviderAndGuestRegistrationRecordExactlyTheAcceptedCurrentVersion(): void
    {
        [$root] = $this->people();
        $policies = $this->policies();
        $policies->update($root, 'rules', 'hosted', 'Community rules', null, true, null, '127.0.0.1');
        $auth = new AuthService($this->pdo, $this->config);
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $this->assertApi(400, 'rules_acceptance_required', fn () => $auth->register('NewMember', 'a safe new password', '127.0.0.3'));
        $this->assertApi(409, 'rules_version_outdated', fn () => $auth->register('NewMember', 'a safe new password', '127.0.0.3', null, 99));
        $this->assertApi(400, 'rules_acceptance_required', fn () => $auth->registerWithProvider('ProviderMember', 'google', 'subject', '127.0.0.4'));
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
        $this->assertApi(400, 'rules_acceptance_required', fn () => (new GuestService($this->pdo))->start('10.0.0.1'));
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $created = [
            $auth->register('NewMember', 'a safe new password', '127.0.0.3', null, 1),
            $auth->registerWithProvider('ProviderMember', 'google', 'subject', '127.0.0.4', null, 1),
            (new GuestService($this->pdo))->start('10.0.0.1', 1),
        ];
        foreach ($created as $user) {
            self::assertSame(1, $user->toSessionArray()['rules']['accepted_version']);
            self::assertNotNull($user->toSessionArray()['rules']['accepted_at']);
            self::assertFalse($user->toSessionArray()['rules']['acceptance_required']);
            self::assertCount(1, $policies->acceptanceHistory($user->id));
        }
    }

    public function testPrivacyNoticesAreInformationalAndNeverQueuedForPush(): void
    {
        [$root, $member] = $this->people();
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1');
        $policies = $this->policies();
        $first = $policies->update($root, 'privacy', 'hosted', '# Privacy', null, false, 'What is retained', '127.0.0.1');
        self::assertSame(1, $first['version']);
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'privacy_policy_updated'")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'privacy_policy_updated' AND push_dispatched_at IS NULL")->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'privacy_policy_updated' AND user_id = {$guest->id}")->fetchColumn());
        self::assertFalse($policies->rulesStatus($member->id)['acceptance_required']);
        $timeline = (new PrivacyNotificationService($this->pdo))->timeline($member)['notifications'];
        self::assertSame('Privacy policy updated', $timeline[0]['title']);
        self::assertSame(['What is retained'], $timeline[0]['details']);
        self::assertSame('https://chat.test/privacy.php', $timeline[0]['link']);
        $policies->update($root, 'privacy', 'hosted', 'Typo fixed', null, false, null, '127.0.0.1');
        self::assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'privacy_policy_updated'")->fetchColumn());
        self::assertSame(2, $policies->update($root, 'privacy', 'external', null, 'https://example.org/privacy', true, null, '127.0.0.1')['version']);
        self::assertSame(4, (int) $this->pdo->query("SELECT COUNT(*) FROM account_notifications WHERE kind = 'privacy_policy_updated'")->fetchColumn());
    }

    public function testNewContentIsGatedButSafetyReadingDeletionModerationAndAccountControlsRemainAvailable(): void
    {
        [$root, $member] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'general', 'General', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($member, $room->id, '127.0.0.2');
        $messages = new MessageService($this->pdo);
        $own = $messages->send($member, $room->id, 'My earlier message');
        $other = $messages->send($root, $room->id, 'A reportable message');
        $dm = (new DirectMessageService($this->pdo))->send($member, $root->id, 'Earlier private message');
        (new RoomReactionService($this->pdo))->add($member, $other['id'], '👍');
        (new DirectMessageReactionService($this->pdo))->add($member, $dm['id'], '👍');
        $policies = $this->policies();
        $policies->update($root, 'rules', 'hosted', 'New community rules', null, true, null, '127.0.0.1');
        $uploadPath = TestScratch::file('policy-blocked-upload', 'unprocessed bytes');
        $file = IncomingFile::forTesting('sample.txt', $uploadPath);
        try {
            $blocked = [
                fn () => $messages->send($member, $room->id, 'New content'),
                fn () => (new RoomMessageMutationService($this->pdo))->edit($member, $own['id'], 'Edited content', '127.0.0.2'),
                fn () => (new DirectMessageService($this->pdo))->send($member, $root->id, 'New private content'),
                fn () => (new DirectMessageMutationService($this->pdo))->edit($member, $dm['id'], 'Edited private content', '127.0.0.2'),
                fn () => (new PingService($this->pdo))->send($member, $room->id, $root->username, 'A ping'),
                fn () => (new RoomReactionService($this->pdo))->add($member, $own['id'], '❤️'),
                fn () => (new DirectMessageReactionService($this->pdo))->add($member, $dm['id'], '❤️'),
                fn () => (new AvatarService($this->pdo, $this->config))->upload($member, $file, '127.0.0.2'),
                fn () => (new AttachmentService($this->pdo, $this->config))->upload($member, $room->id, $file, '', '127.0.0.2'),
                fn () => (new DirectMessageAttachmentService($this->pdo, $this->config))->upload($member, $root->id, $file, '', '127.0.0.2'),
            ];
            foreach ($blocked as $operation) {
                $this->assertApi(403, 'rules_acceptance_required', $operation);
            }
            self::assertSame('unprocessed bytes', file_get_contents($uploadPath));
        } finally {
            TestScratch::remove($uploadPath);
        }
        self::assertCount(2, $messages->history($member, $room->id));
        self::assertCount(1, (new DirectMessageService($this->pdo))->history($member, $root->id));
        $ignores = new IgnoreService($this->pdo);
        $ignores->setIgnored($member, $root->id, true);
        self::assertTrue($ignores->isIgnoring($member->id, $root->id));
        $ignores->setIgnored($member, $root->id, false);
        $blocks = new DirectMessageBlockService($this->pdo);
        self::assertTrue($blocks->block($member, $root->id)['blocked_by_me']);
        self::assertFalse($blocks->unblock($member, $root->id)['blocked_by_me']);
        self::assertSame([], (new RoomReactionService($this->pdo))->remove($member, $other['id'], '👍'));
        self::assertSame([], (new DirectMessageReactionService($this->pdo))->remove($member, $dm['id'], '👍'));
        $avatarConfig = $this->configWith(['attachmentStoragePath' => TestScratch::directory('policy-avatar-storage')]);
        $avatarFiles = new AttachmentFileStore($avatarConfig);
        $avatarKey = $avatarFiles->storeBytes('private fixture bytes');
        $this->pdo->prepare('UPDATE users SET avatar_key = :key, avatar_updated_at = NOW() WHERE id = :id')
            ->execute(['key' => $avatarKey, 'id' => $member->id]);
        (new AvatarService($this->pdo, $avatarConfig))->remove($member, $member->id, '127.0.0.2');
        self::assertFileDoesNotExist($avatarFiles->pathForKey($avatarKey));
        (new DisplayPreferenceService($this->pdo))->update($member->id, 'en-GB', 'h23');
        $report = (new ReportService($this->pdo))->reportRoomMessage($member, $other['id'], 'other', 'Please review this message', '127.0.0.2');
        self::assertSame('open', $report['status']);
        $mute = (new MuteService($this->pdo))->mute($root, $member->id, $room->id, null, 'Moderation still works', false, '127.0.0.1');
        (new MuteService($this->pdo))->lift($root, $mute['id'], '127.0.0.1');
        $subscriptions = PushFixtures::subscriptions($this->pdo);
        $subscriptions->subscribe($member, 'https://push.example.org/device', PushFixtures::key(), PushFixtures::auth(), null, PushFixtures::login($this->pdo, $member, str_repeat('a', 64)));
        $subscriptions->revoke($member, $subscriptions->list($member)[0]['id']);
        self::assertSame([], $subscriptions->list($member));
        $policies->accept($member, 1, '127.0.0.2');
        self::assertSame('After agreement', $messages->send($member, $room->id, 'After agreement')['body']);
        $policies->update($root, 'rules', 'hosted', 'Material update', null, true, null, '127.0.0.1');
        $export = (new PersonalDataExportService($this->pdo, $this->config))->export($member, '127.0.0.2');
        self::assertSame([1], array_column($export['account']['rules_acceptances'], 'version'));
        (new RoomMessageMutationService($this->pdo))->deleteOwn($member, $own['id'], '127.0.0.2');
        (new DirectMessageMutationService($this->pdo))->deleteOwn($member, $dm['id'], '127.0.0.2');
        $fresh = (new AuthService($this->pdo, $this->config))->changePassword($member, 'another secure password', 'a changed secure password', '127.0.0.2');
        (new AccountClosureService($this->pdo, $this->config))->request($fresh, '127.0.0.2');
        self::assertSame('closure_pending', $this->pdo->query("SELECT account_state FROM users WHERE id = {$member->id}")->fetchColumn());
    }

    public function testGuestsCanReadButMustAcceptMaterialUpdatesBeforePosting(): void
    {
        [$root] = $this->people();
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'lobby', 'Lobby', '', 'public', 0, 0, '127.0.0.1', 'write');
        $this->pdo->exec('UPDATE system_settings SET guest_access_enabled = TRUE WHERE id = 1');
        $policies = $this->policies();
        $policies->update($root, 'rules', 'hosted', 'Rules one', null, true, null, '127.0.0.1');
        $guest = (new GuestService($this->pdo))->start('10.0.0.1', 1);
        $rooms->join($guest, $room->id, '10.0.0.1');
        $policies->update($root, 'rules', 'hosted', 'Rules two', null, true, null, '127.0.0.1');
        self::assertTrue((new UserRepository($this->pdo))->findAuthenticatedById($guest->id)->toSessionArray()['rules']['acceptance_required']);
        $messages = new MessageService($this->pdo);
        self::assertSame([], $messages->history($guest, $room->id));
        $this->assertApi(403, 'rules_acceptance_required', fn () => $messages->send($guest, $room->id, 'Before agreement'));
        $policies->accept($guest, 2, '10.0.0.1');
        self::assertSame('After agreement', $messages->send($guest, $room->id, 'After agreement')['body']);
    }

    public function testPublishingWaitsForAnAlreadyAuthorizedContentTransaction(): void
    {
        [$root, $member] = $this->people();
        $policies = $this->policies();
        $policies->update($root, 'rules', 'hosted', 'Rules one', null, true, null, '127.0.0.1');
        $policies->accept($member, 1, '127.0.0.2');
        $other = Database::connect($this->config);
        $other->exec("SET lock_timeout = '100ms'");
        $this->pdo->beginTransaction();
        try {
            $policies->assertMayWrite($member);
            try {
                (new PolicyService($other, $this->config))->update($root, 'rules', 'hosted', 'Rules two', null, true, null, '127.0.0.1');
                self::fail('A material publication must wait for the held rules snapshot.');
            } catch (PDOException $exception) {
                self::assertSame('55P03', $exception->getCode());
            }
        } finally {
            $this->pdo->rollBack();
        }
        (new PolicyService($other, $this->config))->update($root, 'rules', 'hosted', 'Rules two', null, true, null, '127.0.0.1');
        $this->assertApi(403, 'rules_acceptance_required', fn () => $policies->assertMayWrite($member));
    }

    public function testStaleSessionsCannotRecreateAcceptanceHistoryAfterAccountTombstoning(): void
    {
        [$root, $member] = $this->people();
        $policies = $this->policies();
        $policies->update($root, 'rules', 'hosted', 'Rules', null, true, null, '127.0.0.1');
        $policies->accept($member, 1, '127.0.0.2');
        $this->pdo->exec("UPDATE users SET account_state = 'closed', closure_requested_at = NOW() - INTERVAL '15 days', closure_finalizes_at = NOW() - INTERVAL '1 day', closed_at = NOW(), session_version = session_version + 1 WHERE id = {$member->id}");
        self::assertSame([], $policies->acceptanceHistory($member->id));
        $this->assertApi(401, 'authentication_required', fn () => $policies->accept($member, 1, '127.0.0.2'));
        self::assertSame([], $policies->acceptanceHistory($member->id));
    }

    public function testPreviewUsesPublishedRendererAndAdminUrlsAreValidated(): void
    {
        [$root, $member] = $this->people();
        $policies = $this->policies();
        $this->assertApi(403, 'forbidden', fn () => $policies->preview($member, 'text'));
        $this->assertApi(400, 'validation_error', fn () => $policies->update($root, 'rules', 'external', null, 'http://example.org', true, null, '127.0.0.1'));
        $this->assertApi(400, 'validation_error', fn () => $policies->update($root, 'privacy', 'external', null, 'https://user:pass@example.org', true, null, '127.0.0.1'));
        $markdown = '# Rules' . "\n\n" . '**Stay kind**';
        $policies->update($root, 'rules', 'hosted', $markdown, null, true, null, '127.0.0.1');
        self::assertSame($policies->preview($root, $markdown), $policies->publicDocument('rules')['html']);
        self::assertSame('https://chat.test/rules.php', $policies->discovery()['rules']['url']);
        $policies->update($root, 'legal_notice', 'none', null, 'https://example.org/legal', false, null, '127.0.0.1');
        self::assertSame(['url' => 'https://example.org/legal'], $policies->discovery()['legal_notice']);
        self::assertSame('https://example.org/legal', $policies->legalNoticeUrl());
        $policies->update($root, 'legal_notice', 'none', null, null, false, null, '127.0.0.1');
        self::assertNull($policies->legalNoticeUrl());
    }

    public function testHostedDiscoveryAndPrivacyNoticesUseTheCanonicalOriginFallbackChain(): void
    {
        [$root, $member] = $this->people();
        $server = $_SERVER;
        try {
            $_SERVER['HTTP_HOST'] = 'request.example:8080';
            unset($_SERVER['HTTPS']);
            $_SERVER['HTTP_X_FORWARDED_HOST'] = 'untrusted.example';
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $base = ['applicationOrigin' => '', 'oidcRedirectOrigin' => '', 'webauthnOrigin' => '', 'webauthnRpId' => ''];
            $configured = new PolicyService($this->pdo, $this->configWith(array_replace($base, [
                'applicationOrigin' => 'https://canonical.example',
                'oidcRedirectOrigin' => 'https://provider.example',
                'webauthnOrigin' => 'https://passkeys.example', 'webauthnRpId' => 'passkeys.example',
            ])));
            $configured->update($root, 'rules', 'hosted', 'Rules', null, false, null, '127.0.0.1');
            $configured->update($root, 'privacy', 'hosted', 'Privacy', null, false, null, '127.0.0.1');
            self::assertSame('https://canonical.example/rules.php', $configured->discovery()['rules']['url']);
            self::assertSame('https://canonical.example/privacy.php',
                (new PrivacyNotificationService($this->pdo))->timeline($member)['notifications'][0]['link']);
            $oidc = new PolicyService($this->pdo, $this->configWith(array_replace($base, [
                'oidcRedirectOrigin' => 'https://provider.example',
                'webauthnOrigin' => 'https://passkeys.example', 'webauthnRpId' => 'passkeys.example',
            ])));
            self::assertSame('https://provider.example/rules.php', $oidc->discovery()['rules']['url']);
            $passkeys = new PolicyService($this->pdo, $this->configWith(array_replace($base, [
                'webauthnOrigin' => 'https://passkeys.example', 'webauthnRpId' => 'passkeys.example',
            ])));
            self::assertSame('https://passkeys.example/rules.php', $passkeys->discovery()['rules']['url']);
            $request = new PolicyService($this->pdo, $this->configWith($base));
            self::assertSame('http://request.example:8080/rules.php', $request->discovery()['rules']['url']);
            $_SERVER['HTTPS'] = 'on';
            self::assertSame('https://request.example:8080/rules.php', $request->discovery()['rules']['url']);
            $_SERVER['HTTP_HOST'] = 'invalid host';
            $this->assertApi(400, 'invalid_host', fn () => $request->discovery());
        } finally {
            $_SERVER = $server;
        }
    }

    /** @return array{AuthenticatedUser,AuthenticatedUser} */
    private function people(): array
    {
        $auth = new AuthService($this->pdo, $this->config);
        return [$auth->register('Root', 'a very secure password', '127.0.0.1'),
            $auth->register('Member', 'another secure password', '127.0.0.2')];
    }

    private function policies(): PolicyService
    {
        return new PolicyService($this->pdo, $this->configWith(['oidcRedirectOrigin' => 'https://chat.test']));
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
