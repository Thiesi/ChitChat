<?php

declare(strict_types=1);
namespace ChitChat\Account;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use PDO;
use RuntimeException;
use Throwable;

final class PersonalDataExportService
{
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
        $this->audit = new AuditLogger($pdo);
    }

    /** @return array<string, mixed> */
    public function export(AuthenticatedUser $actor, string $ipAddress): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            $profile = $this->profile($actor->id);
            $roles = $this->roles($actor->id);
            $bans = $this->bans($actor->id);
            $createdRooms = $this->createdRooms($actor->id);
            $memberships = $this->memberships($actor->id);
            $invitations = $this->invitations($actor->id);
            $roomMessages = $this->roomMessages($actor->id);
            $roomRevisions = $this->roomRevisions($actor->id);
            $directMessages = $this->directMessages($actor->id);
            $directMessageRevisions = $this->directMessageRevisions($actor->id);
            $blocks = $this->blocks($actor->id);
            $submittedReports = $this->submittedReports($actor->id);
            $loginAttempts = $this->loginAttempts($actor->id);
            $activity = $this->activity($actor->id);

            $export = [
                'format' => [
                    'name' => 'chitchat-personal-data-export',
                    'version' => 1,
                ],
                'generated_at' => gmdate(DATE_ATOM),
                'application' => [
                    'name' => $this->config->applicationName,
                    'version' => $this->config->applicationVersion,
                ],
                'scope' => [
                    'description' => 'Retained personal data currently associated with the authenticated account.',
                    'includes' => [
                        'account profile, roles and ban history',
                        'rooms created by the account, memberships and pending invitations',
                        'retained room messages authored by the account and their retained revisions',
                        'retained direct messages visible to the account and attachment metadata',
                        'retained revisions only for direct messages authored by the account',
                        'direct-message blocks created by the account',
                        'moderation reports submitted by the account, including its own details and retained exact-message evidence snapshots',
                        'login-attempt history associated with the account username',
                        'audit entries where the account is the recorded actor',
                    ],
                    'excludes' => [
                        'password hashes, session state, CSRF tokens and privileged-step-up state',
                        'attachment file bytes and internal attachment storage keys',
                        'direct-message blocks created by other users',
                        'revision history for messages authored by other users',
                        'moderation reports submitted by other users, queue assignments and moderator resolution notes',
                        'audit entries and source IP addresses belonging only to another actor',
                    ],
                ],
                'account' => array_merge($profile, [
                    'roles' => $roles,
                    'ban_history' => $bans,
                ]),
                'rooms' => [
                    'created' => $createdRooms,
                    'memberships' => $memberships,
                    'pending_invitations' => $invitations,
                    'authored_messages' => $roomMessages,
                    'authored_message_revisions' => $roomRevisions,
                ],
                'direct_messages' => [
                    'messages' => $directMessages,
                    'authored_message_revisions' => $directMessageRevisions,
                    'blocks_created' => $blocks,
                ],
                'moderation' => [
                    'reports_submitted' => $submittedReports,
                ],
                'security_history' => [
                    'login_attempts' => $loginAttempts,
                ],
                'activity' => $activity,
            ];

            $this->audit->log(
                actorUserId: $actor->id,
                action: 'account.personal_data_exported',
                subjectType: 'user',
                subjectId: (string) $actor->id,
                metadata: [
                    'format_name' => 'chitchat-personal-data-export',
                    'format_version' => 1,
                    'counts' => [
                        'roles' => count($roles),
                        'bans' => count($bans),
                        'created_rooms' => count($createdRooms),
                        'memberships' => count($memberships),
                        'pending_invitations' => count($invitations),
                        'room_messages' => count($roomMessages),
                        'room_message_revisions' => count($roomRevisions),
                        'direct_messages' => count($directMessages),
                        'direct_message_revisions' => count($directMessageRevisions),
                        'blocks_created' => count($blocks),
                        'moderation_reports_submitted' => count($submittedReports),
                        'login_attempts' => count($loginAttempts),
                        'activity_entries' => count($activity),
                    ],
                ],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();

            return $export;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function profile(int $userId): array
    {
        $statement = $this->prepare(<<<'SQL'
SELECT id, username, birth_date, created_at, updated_at, last_login_at
FROM users
WHERE id = :id
SQL, 'personal-data account profile');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('The account no longer exists while preparing its personal-data export.');
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'birth_date' => $this->nullableString($row['birth_date']),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'last_login_at' => $this->nullableString($row['last_login_at']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function roles(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT role, granted_at
FROM user_roles
WHERE user_id = :user_id
ORDER BY granted_at, role
SQL, ['user_id' => $userId], 'personal-data roles', static fn (array $row): array => [
            'role' => (string) $row['role'],
            'granted_at' => (string) $row['granted_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function bans(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT ban.id,
       ban.reason,
       ban.starts_at,
       ban.expires_at,
       ban.revoked_at,
       creator.id AS created_by_id,
       creator.username AS created_by_username,
       revoker.id AS revoked_by_id,
       revoker.username AS revoked_by_username
FROM user_bans ban
JOIN users creator ON creator.id = ban.created_by
LEFT JOIN users revoker ON revoker.id = ban.revoked_by
WHERE ban.user_id = :user_id
ORDER BY ban.id
SQL, ['user_id' => $userId], 'personal-data ban history', fn (array $row): array => [
            'id' => (int) $row['id'],
            'reason' => (string) $row['reason'],
            'starts_at' => (string) $row['starts_at'],
            'expires_at' => $this->nullableString($row['expires_at']),
            'revoked_at' => $this->nullableString($row['revoked_at']),
            'created_by' => $this->userReference($row['created_by_id'], $row['created_by_username']),
            'revoked_by' => $this->userReference($row['revoked_by_id'], $row['revoked_by_username']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function createdRooms(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT id, room_key, name, info_line, visibility, minimum_age,
       inactivity_timeout_seconds, created_at, updated_at, deleted_at
FROM rooms
WHERE created_by = :user_id
ORDER BY id
SQL, ['user_id' => $userId], 'personal-data created rooms', fn (array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['room_key'],
            'name' => (string) $row['name'],
            'info_line' => (string) $row['info_line'],
            'visibility' => (string) $row['visibility'],
            'minimum_age' => (int) $row['minimum_age'],
            'inactivity_timeout_seconds' => (int) $row['inactivity_timeout_seconds'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'deleted_at' => $this->nullableString($row['deleted_at']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function memberships(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT room.id, room.room_key, room.name, room.visibility, room.minimum_age,
       room.deleted_at, member.role, member.joined_at
FROM room_members member
JOIN rooms room ON room.id = member.room_id
WHERE member.user_id = :user_id
ORDER BY member.joined_at, room.id
SQL, ['user_id' => $userId], 'personal-data room memberships', fn (array $row): array => [
            'room' => $this->roomReference($row),
            'role' => (string) $row['role'],
            'joined_at' => (string) $row['joined_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function invitations(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT room.id, room.room_key, room.name, room.visibility, room.minimum_age,
       room.deleted_at, invitation.created_at,
       inviter.id AS invited_by_id, inviter.username AS invited_by_username
FROM room_invitations invitation
JOIN rooms room ON room.id = invitation.room_id
JOIN users inviter ON inviter.id = invitation.invited_by
WHERE invitation.user_id = :user_id
ORDER BY invitation.created_at, room.id
SQL, ['user_id' => $userId], 'personal-data room invitations', fn (array $row): array => [
            'room' => $this->roomReference($row),
            'invited_by' => $this->userReference($row['invited_by_id'], $row['invited_by_username']),
            'created_at' => (string) $row['created_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function roomMessages(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT message.id,
       message.message_type,
       message.body,
       message.created_at,
       message.edited_at,
       message.deleted_at,
       room.id AS room_id,
       room.room_key,
       room.name AS room_name,
       attachment.id AS attachment_id,
       attachment.original_name,
       attachment.mime_type,
       attachment.size_bytes,
       attachment.sha256,
       attachment.created_at AS attachment_created_at,
       attachment.deleted_at AS attachment_deleted_at
FROM room_messages message
JOIN rooms room ON room.id = message.room_id
LEFT JOIN attachments attachment ON attachment.message_id = message.id
WHERE message.sender_id = :user_id
ORDER BY message.id
SQL, ['user_id' => $userId], 'personal-data authored room messages', fn (array $row): array => [
            'id' => (int) $row['id'],
            'room' => [
                'id' => (int) $row['room_id'],
                'key' => (string) $row['room_key'],
                'name' => (string) $row['room_name'],
            ],
            'message_type' => (string) $row['message_type'],
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
            'edited_at' => $this->nullableString($row['edited_at']),
            'deleted_at' => $this->nullableString($row['deleted_at']),
            'attachment' => $row['attachment_id'] === null ? null : [
                'id' => (int) $row['attachment_id'],
                'name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'sha256' => (string) $row['sha256'],
                'created_at' => (string) $row['attachment_created_at'],
                'deleted_at' => $this->nullableString($row['attachment_deleted_at']),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function roomRevisions(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT revision.id,
       revision.message_id,
       revision.action,
       revision.message_type,
       revision.body_before,
       revision.body_after,
       revision.created_at,
       actor.id AS actor_id,
       actor.username AS actor_username
FROM room_message_revisions revision
JOIN room_messages message ON message.id = revision.message_id
LEFT JOIN users actor ON actor.id = revision.actor_user_id
WHERE message.sender_id = :user_id
ORDER BY revision.id
SQL, ['user_id' => $userId], 'personal-data room message revisions', fn (array $row): array => [
            'id' => (int) $row['id'],
            'message_id' => (int) $row['message_id'],
            'action' => (string) $row['action'],
            'actor' => $this->userReference($row['actor_id'], $row['actor_username']),
            'message_type' => (string) $row['message_type'],
            'body_before' => (string) $row['body_before'],
            'body_after' => $this->nullableString($row['body_after']),
            'created_at' => (string) $row['created_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function directMessages(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT message.id,
       message.body,
       message.recipient_read_at,
       message.created_at,
       message.edited_at,
       message.deleted_at,
       sender.id AS sender_id,
       sender.username AS sender_username,
       recipient.id AS recipient_id,
       recipient.username AS recipient_username,
       attachment.id AS attachment_id,
       attachment.original_name,
       attachment.mime_type,
       attachment.size_bytes,
       attachment.sha256,
       attachment.created_at AS attachment_created_at,
       attachment.deleted_at AS attachment_deleted_at
FROM direct_messages message
JOIN users sender ON sender.id = message.sender_user_id
JOIN users recipient ON recipient.id = message.recipient_user_id
LEFT JOIN direct_message_attachments attachment ON attachment.direct_message_id = message.id
WHERE message.sender_user_id = :sender_id OR message.recipient_user_id = :recipient_id
ORDER BY message.id
SQL, ['sender_id' => $userId, 'recipient_id' => $userId], 'personal-data direct messages', fn (array $row): array => [
            'id' => (int) $row['id'],
            'sender' => $this->userReference($row['sender_id'], $row['sender_username']),
            'recipient' => $this->userReference($row['recipient_id'], $row['recipient_username']),
            'body' => (string) $row['body'],
            'recipient_read_at' => $this->nullableString($row['recipient_read_at']),
            'created_at' => (string) $row['created_at'],
            'edited_at' => $this->nullableString($row['edited_at']),
            'deleted_at' => $this->nullableString($row['deleted_at']),
            'attachment' => $row['attachment_id'] === null ? null : [
                'id' => (int) $row['attachment_id'],
                'name' => (string) $row['original_name'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'sha256' => (string) $row['sha256'],
                'created_at' => (string) $row['attachment_created_at'],
                'deleted_at' => $this->nullableString($row['attachment_deleted_at']),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function directMessageRevisions(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT revision.id,
       revision.message_id,
       revision.action,
       revision.body_before,
       revision.body_after,
       revision.created_at,
       actor.id AS actor_id,
       actor.username AS actor_username
FROM direct_message_revisions revision
JOIN direct_messages message ON message.id = revision.message_id
LEFT JOIN users actor ON actor.id = revision.actor_user_id
WHERE message.sender_user_id = :user_id
ORDER BY revision.id
SQL, ['user_id' => $userId], 'personal-data direct-message revisions', fn (array $row): array => [
            'id' => (int) $row['id'],
            'message_id' => (int) $row['message_id'],
            'action' => (string) $row['action'],
            'actor' => $this->userReference($row['actor_id'], $row['actor_username']),
            'body_before' => (string) $row['body_before'],
            'body_after' => $this->nullableString($row['body_after']),
            'created_at' => (string) $row['created_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function blocks(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT blocked.id AS blocked_user_id,
       blocked.username AS blocked_username,
       block.created_at
FROM direct_message_blocks block
JOIN users blocked ON blocked.id = block.blocked_user_id
WHERE block.blocker_user_id = :user_id
ORDER BY block.created_at, blocked.id
SQL, ['user_id' => $userId], 'personal-data direct-message blocks', fn (array $row): array => [
            'blocked_user' => $this->userReference($row['blocked_user_id'], $row['blocked_username']),
            'created_at' => (string) $row['created_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function submittedReports(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT report.id,
       report.case_id,
       report.category,
       report.details,
       report.evidence_body,
       report.evidence_json,
       report.created_at,
       moderation_case.message_kind,
       moderation_case.message_id,
       moderation_case.status,
       moderation_case.resolution_code,
       moderation_case.resolved_at,
       room.id AS room_id,
       room.room_key,
       room.name AS room_name,
       subject.id AS subject_user_id,
       subject.username AS subject_username
FROM moderation_reports report
JOIN moderation_cases moderation_case ON moderation_case.id = report.case_id
JOIN users subject ON subject.id = moderation_case.subject_user_id
LEFT JOIN rooms room ON room.id = moderation_case.room_id
WHERE report.reporter_user_id = :user_id
ORDER BY report.id
SQL, ['user_id' => $userId], 'personal-data submitted moderation reports', fn (array $row): array => [
            'id' => (int) $row['id'],
            'case_id' => (int) $row['case_id'],
            'message_kind' => (string) $row['message_kind'],
            'message_id' => (int) $row['message_id'],
            'room' => $row['room_id'] === null ? null : [
                'id' => (int) $row['room_id'],
                'key' => (string) $row['room_key'],
                'name' => (string) $row['room_name'],
            ],
            'subject' => $this->userReference($row['subject_user_id'], $row['subject_username']),
            'category' => (string) $row['category'],
            'details' => $this->nullableString($row['details']),
            'evidence_body' => $this->nullableString($row['evidence_body']),
            'evidence' => $this->jsonObject($row['evidence_json']),
            'created_at' => (string) $row['created_at'],
            'case_status' => (string) $row['status'],
            'resolution_code' => $this->nullableString($row['resolution_code']),
            'resolved_at' => $this->nullableString($row['resolved_at']),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function loginAttempts(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT attempt.id,
       attempt.ip_address,
       attempt.successful,
       attempt.reason,
       attempt.created_at
FROM login_attempts attempt
JOIN users account ON account.username_canonical = attempt.username_canonical
WHERE account.id = :user_id
ORDER BY attempt.id
SQL, ['user_id' => $userId], 'personal-data login attempts', fn (array $row): array => [
            'id' => (int) $row['id'],
            'ip_address' => (string) $row['ip_address'],
            'successful' => $this->databaseBoolean($row['successful']),
            'reason' => (string) $row['reason'],
            'created_at' => (string) $row['created_at'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function activity(int $userId): array
    {
        return $this->mappedRows(<<<'SQL'
SELECT id, action, subject_type, subject_id, metadata_json, ip_address, created_at
FROM audit_log
WHERE actor_user_id = :user_id
ORDER BY id
SQL, ['user_id' => $userId], 'personal-data audit activity', fn (array $row): array => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => $this->nullableString($row['subject_id']),
            'metadata' => $this->jsonObject($row['metadata_json']),
            'ip_address' => (string) $row['ip_address'],
            'created_at' => (string) $row['created_at'],
        ]);
    }

    /**
     * @param array<string, int|string> $parameters
     * @param callable(array<string, mixed>): array<string, mixed> $mapper
     * @return list<array<string, mixed>>
     */
    private function mappedRows(
        string $sql,
        array $parameters,
        string $purpose,
        callable $mapper,
    ): array {
        $statement = $this->prepare($sql, $purpose);
        $statement->execute($parameters);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $result[] = $mapper($row);
            }
        }

        return $result;
    }

    private function prepare(string $sql, string $purpose): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException(sprintf('Unable to prepare %s lookup.', $purpose));
        }

        return $statement;
    }

    /** @return array{id:?int, username:?string} */
    private function userReference(mixed $id, mixed $username): array
    {
        return [
            'id' => $id === null ? null : (int) $id,
            'username' => $this->nullableString($username),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function roomReference(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'key' => (string) $row['room_key'],
            'name' => (string) $row['name'],
            'visibility' => (string) $row['visibility'],
            'minimum_age' => (int) $row['minimum_age'],
            'deleted_at' => $this->nullableString($row['deleted_at']),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
