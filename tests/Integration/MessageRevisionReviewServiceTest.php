<?php

declare(strict_types=1);

namespace ChitChat\Tests\Integration;

use ChitChat\Admin\MessageRevisionReviewService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\DirectMessage\DirectMessageInspectionService;
use ChitChat\DirectMessage\DirectMessageMutationService;
use ChitChat\DirectMessage\DirectMessageService;
use ChitChat\Http\ApiException;
use ChitChat\Room\MessageService;
use ChitChat\Room\RoomMessageMutationService;
use ChitChat\Room\RoomService;
use JsonException;

final class MessageRevisionReviewServiceTest extends DatabaseTestCase
{
    /** @throws JsonException */
    public function testSuperAdministratorReviewsRoomChainAndAuditOmitsBodies(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $author = $auth->register('Author', 'another secure password', '127.0.0.2');
        $rooms = new RoomService($this->pdo);
        $room = $rooms->create($root, 'review-room', 'Review Room', '', 'public', 0, 0, '127.0.0.1');
        $rooms->join($author, $room->id, '127.0.0.2');
        $message = (new MessageService($this->pdo))->send($author, $room->id, 'Original room evidence');
        $mutations = new RoomMessageMutationService($this->pdo);
        $mutations->edit($author, $message['id'], 'Edited room evidence', '127.0.0.2');
        $mutations->deleteOwn($author, $message['id'], '127.0.0.2');

        $result = (new MessageRevisionReviewService(
            $this->pdo,
            $this->reviewConfig(enabled: true, role: 'super_admin'),
        ))->review(
            actor: $root,
            kindInput: 'room',
            messageId: $message['id'],
            reasonInput: 'Investigating a reported moderation incident',
            ipAddress: '127.0.0.1',
        );

        self::assertSame('room', $result['kind']);
        self::assertSame($message['id'], $result['message']['id']);
        self::assertArrayNotHasKey('body', $result['message']);
        $roomContext = $result['message']['room'] ?? null;
        self::assertIsArray($roomContext);
        self::assertSame('review-room', $roomContext['key']);
        self::assertCount(2, $result['revisions']);
        self::assertSame('edit', $result['revisions'][0]['action']);
        self::assertSame('Original room evidence', $result['revisions'][0]['body_before']);
        self::assertSame('Edited room evidence', $result['revisions'][0]['body_after']);
        self::assertSame('delete', $result['revisions'][1]['action']);
        self::assertSame('Edited room evidence', $result['revisions'][1]['body_before']);
        self::assertNull($result['revisions'][1]['body_after']);

        $audit = $this->pdo->query(<<<'SQL'
SELECT subject_type, subject_id, metadata_json::text AS metadata
FROM audit_log
WHERE action = 'admin.message_revisions_reviewed'
ORDER BY id DESC
LIMIT 1
SQL)->fetch();
        self::assertIsArray($audit);
        self::assertSame('message_revision_history', $audit['subject_type']);
        self::assertSame('room:' . $message['id'], $audit['subject_id']);
        $metadata = json_decode((string) $audit['metadata'], true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);
        self::assertSame('Investigating a reported moderation incident', $metadata['reason']);
        self::assertSame(2, $metadata['revision_count']);
        self::assertSame(['edit', 'delete'], $metadata['revision_actions']);
        self::assertStringNotContainsString('Original room evidence', (string) $audit['metadata']);
        self::assertStringNotContainsString('Edited room evidence', (string) $audit['metadata']);
    }

    public function testDirectMessageInspectionPermissionDoesNotGrantRevisionReview(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $operator = $auth->register('Operator', 'another secure password', '127.0.0.2');
        $alice = $auth->register('Alice', 'different secure password', '127.0.0.3');
        $bob = $auth->register('Bob', 'further secure password', '127.0.0.4');
        $this->pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$operator->id}, 'admin')");
        $operator = (new UserRepository($this->pdo))->findAuthenticatedById($operator->id);
        self::assertNotNull($operator);

        $message = (new DirectMessageService($this->pdo))->send($alice, $bob->id, 'Original private evidence');
        (new DirectMessageMutationService($this->pdo))->edit(
            $alice,
            $message['id'],
            'Edited private evidence',
            '127.0.0.3',
        );
        $separatePolicy = $this->reviewConfig(
            enabled: true,
            role: 'super_admin',
            inspectionEnabled: true,
            inspectionRole: 'admin',
        );

        $inspection = (new DirectMessageInspectionService($this->pdo, $separatePolicy))->inspect(
            $operator,
            $alice->id,
            $bob->id,
            'Routine operational inspection',
            null,
            50,
            '127.0.0.2',
        );
        self::assertCount(1, $inspection['messages']);

        try {
            (new MessageRevisionReviewService($this->pdo, $separatePolicy))->review(
                $operator,
                'direct',
                $message['id'],
                'Reviewing a disputed historical edit',
                '127.0.0.2',
            );
            self::fail('Expected independently restricted revision-review rejection.');
        } catch (ApiException $exception) {
            self::assertSame('forbidden', $exception->errorCode);
        }

        $adminPolicy = $this->reviewConfig(enabled: true, role: 'admin');
        $review = (new MessageRevisionReviewService($this->pdo, $adminPolicy))->review(
            $operator,
            'direct',
            $message['id'],
            'Reviewing a disputed historical edit',
            '127.0.0.2',
        );
        self::assertSame('direct', $review['kind']);
        self::assertSame('Original private evidence', $review['revisions'][0]['body_before']);
        self::assertSame('Edited private evidence', $review['revisions'][0]['body_after']);
        self::assertTrue($root->hasRole('super_admin'));
    }

    public function testDisabledPolicyMissingHistoryAndShortReasonDoNotWriteReviewAudit(): void
    {
        $auth = new AuthService($this->pdo, $this->config);
        $root = $auth->register('Root', 'a very secure password', '127.0.0.1');
        $alice = $auth->register('Alice', 'another secure password', '127.0.0.2');
        $bob = $auth->register('Bob', 'different secure password', '127.0.0.3');
        $message = (new DirectMessageService($this->pdo))->send($alice, $bob->id, 'Unchanged private message');
        $before = $this->reviewAuditCount();

        try {
            (new MessageRevisionReviewService(
                $this->pdo,
                $this->reviewConfig(enabled: false, role: 'super_admin'),
            ))->review(
                $root,
                'direct',
                $message['id'],
                'Valid operational review reason',
                '127.0.0.1',
            );
            self::fail('Expected disabled revision-review rejection.');
        } catch (ApiException $exception) {
            self::assertSame('message_revision_review_disabled', $exception->errorCode);
        }

        $enabled = new MessageRevisionReviewService(
            $this->pdo,
            $this->reviewConfig(enabled: true, role: 'super_admin'),
        );
        try {
            $enabled->review(
                $root,
                'direct',
                $message['id'],
                'Valid operational review reason',
                '127.0.0.1',
            );
            self::fail('Expected no-revision-history rejection.');
        } catch (ApiException $exception) {
            self::assertSame('revision_history_not_found', $exception->errorCode);
        }

        try {
            $enabled->review($root, 'direct', $message['id'], 'too short', '127.0.0.1');
            self::fail('Expected review-reason validation failure.');
        } catch (ApiException $exception) {
            self::assertSame('revision_review_reason_required', $exception->errorCode);
        }

        self::assertSame($before, $this->reviewAuditCount());
    }

    private function reviewAuditCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'admin.message_revisions_reviewed'",
        )->fetchColumn();
    }

    /**
     * @param 'super_admin'|'admin' $role
     * @param 'super_admin'|'admin' $inspectionRole
     */
    private function reviewConfig(
        bool $enabled,
        string $role,
        bool $inspectionEnabled = true,
        string $inspectionRole = 'super_admin',
    ): Config {
        return new Config(
            environment: $this->config->environment,
            debug: $this->config->debug,
            applicationName: $this->config->applicationName,
            applicationVersion: $this->config->applicationVersion,
            databaseHost: $this->config->databaseHost,
            databasePort: $this->config->databasePort,
            databaseName: $this->config->databaseName,
            databaseUser: $this->config->databaseUser,
            databasePassword: $this->config->databasePassword,
            databaseSslMode: $this->config->databaseSslMode,
            sessionName: $this->config->sessionName,
            sessionCookieSecure: $this->config->sessionCookieSecure,
            sessionCookieSameSite: $this->config->sessionCookieSameSite,
            loginMaxAttempts: $this->config->loginMaxAttempts,
            loginLockMinutes: $this->config->loginLockMinutes,
            presenceLeaseSeconds: $this->config->presenceLeaseSeconds,
            inactivityWarningSeconds: $this->config->inactivityWarningSeconds,
            attachmentStoragePath: $this->config->attachmentStoragePath,
            attachmentMaxBytes: $this->config->attachmentMaxBytes,
            directMessageInspectionEnabled: $inspectionEnabled,
            directMessageInspectionRole: $inspectionRole,
            messageRevisionReviewEnabled: $enabled,
            messageRevisionReviewRole: $role,
        );
    }
}
