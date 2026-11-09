<?php

declare(strict_types=1);

namespace ChitChat\Admin;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Database\LikePattern;
use ChitChat\Http\ApiException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;

/**
 * @phpstan-type UserRow array{
 * id:int,username:string,roles:list<string>,created_at:string,last_login_at:?string,
 * active_ban:?array{reason:string,expires_at:?string,starts_at:string,created_by:array{id:int,username:string}},
 * active_mute:?array{scope:string,room:?array{id:int,name:string},expires_at:?string,room_mute_count:int},
 * closing:?array{purge_at:string}}
 */
final readonly class UserDirectoryService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Counts and rows use one statement snapshot. Detail joins run only on the bounded page.
     * @return array{users:list<UserRow>,counts:array{all:int,staff:int,banned:int,muted:int,closing:int},next_cursor:?string}
     */
    public function page(AuthenticatedUser $actor, string $search = '', int $afterId = 0, int $limit = 50,
        ?string $filter = null, string $sort = 'id', ?string $cursor = null): array
    {
        if (!$actor->canManageUsers()) {
            throw new ApiException(403, 'forbidden', 'User administration requires Administrator access.');
        }
        $search = strtolower(trim($search));
        if (strlen($search) > 32 || ($search !== '' && preg_match('/\A[A-Za-z0-9_.-]+\z/D', $search) !== 1)) {
            throw new ApiException(400, 'validation_error', 'search must be a supported username prefix of at most 32 characters.');
        }
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new ApiException(400, 'validation_error', 'after_id must not be negative and limit must be between 1 and 100.');
        }
        if (!in_array($filter, [null, 'staff', 'banned', 'muted', 'closing'], true)
            || !in_array($sort, ['id', 'last_sign_in'], true)) {
            throw new ApiException(400, 'validation_error', 'filter or sort is invalid.');
        }
        if ($afterId !== 0 && ($cursor !== null || $sort !== 'id')) {
            throw new ApiException(400, 'validation_error', 'after_id cannot be combined with cursor or last_sign_in sort.');
        }
        $position = $cursor === null ? null : $this->decodeCursor($cursor, $search, $filter, $sort);
        $parameters = ['search_empty' => $search === '' ? 1 : 0, 'search_pattern' => LikePattern::prefix($search)];
        $predicate = match ($filter) {
            'staff' => 'is_staff', 'banned' => 'is_banned', 'muted' => 'is_muted',
            'closing' => "account_state = 'closure_pending'", default => 'TRUE',
        };
        if ($sort === 'id') {
            $predicate .= ' AND id > :after_id';
            $parameters['after_id'] = $position['id'] ?? $afterId;
            $order = 'id ASC';
        } else {
            $order = 'last_login_at DESC NULLS LAST, id DESC';
            if ($position !== null) {
                $parameters['cursor_id'] = $position['id'];
                if ($position['last_login_at'] === null) {
                    $predicate .= ' AND last_login_at IS NULL AND id < :cursor_id';
                } else {
                    $predicate .= ' AND (last_login_at < :cursor_time::timestamptz OR last_login_at IS NULL'
                        . ' OR (last_login_at = :tie_time::timestamptz AND id < :cursor_id))';
                    $parameters['cursor_time'] = $position['last_login_at'];
                    $parameters['tie_time'] = $position['last_login_at'];
                }
            }
        }
        $sql = <<<'SQL'
WITH base AS MATERIALIZED (
    SELECT u.id, u.username, u.created_at, u.last_login_at, u.account_state, u.closure_finalizes_at,
        EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id) AS is_staff,
        EXISTS (SELECT 1 FROM user_bans b WHERE b.user_id = u.id AND b.revoked_at IS NULL
            AND b.starts_at <= NOW() AND (b.expires_at IS NULL OR b.expires_at > NOW())) AS is_banned,
        EXISTS (SELECT 1 FROM user_mutes m WHERE m.user_id = u.id AND m.lifted_at IS NULL
            AND (m.expires_at IS NULL OR m.expires_at > NOW())) AS is_muted
    FROM users u WHERE u.account_kind = 'member'
        AND (:search_empty::integer = 1 OR lower(u.username) LIKE :search_pattern ESCAPE '\')
), counts AS (
    SELECT COUNT(*) AS stats_all, COUNT(*) FILTER (WHERE is_staff) AS stats_staff,
        COUNT(*) FILTER (WHERE is_banned) AS stats_banned,
        COUNT(*) FILTER (WHERE is_muted) AS stats_muted,
        COUNT(*) FILTER (WHERE account_state = 'closure_pending') AS stats_closing FROM base
)
SELECT counts.*, p.*,
    COALESCE(roles.names, '[]'::jsonb)::text AS roles,
    ban.reason AS ban_reason, ban.starts_at AS ban_starts_at, ban.expires_at AS ban_expires_at,
    ban.created_by AS ban_created_by, creator.username AS ban_creator_username,
    mute.id AS mute_id, mute.room_id AS mute_room_id, room.name AS mute_room_name,
    mute.expires_at AS mute_expires_at, mutes.room_count
FROM counts
LEFT JOIN LATERAL (SELECT * FROM base WHERE %PREDICATE% ORDER BY %ORDER% LIMIT :limit) p ON TRUE
LEFT JOIN LATERAL (SELECT jsonb_agg(r.role ORDER BY r.role) AS names FROM user_roles r WHERE r.user_id = p.id) roles ON TRUE
LEFT JOIN LATERAL (
    SELECT b.reason, b.starts_at, b.expires_at, b.created_by FROM user_bans b
    WHERE b.user_id = p.id AND b.revoked_at IS NULL AND b.starts_at <= NOW()
        AND (b.expires_at IS NULL OR b.expires_at > NOW())
    ORDER BY b.starts_at DESC, b.id DESC LIMIT 1
) ban ON TRUE
LEFT JOIN users creator ON creator.id = ban.created_by
LEFT JOIN LATERAL (
    SELECT m.id, m.room_id, m.expires_at FROM user_mutes m
    WHERE m.user_id = p.id AND m.lifted_at IS NULL AND (m.expires_at IS NULL OR m.expires_at > NOW())
    ORDER BY (m.room_id IS NULL) DESC, m.expires_at ASC NULLS LAST, m.id ASC LIMIT 1
) mute ON TRUE
LEFT JOIN rooms room ON room.id = mute.room_id
LEFT JOIN LATERAL (
    SELECT COUNT(DISTINCT m.room_id)::integer AS room_count FROM user_mutes m
    WHERE m.user_id = p.id AND m.lifted_at IS NULL AND (m.expires_at IS NULL OR m.expires_at > NOW())
) mutes ON TRUE
ORDER BY %OUTER_ORDER%
SQL;
        $sql = str_replace(['%PREDICATE%', '%ORDER%', '%OUTER_ORDER%'],
            [$predicate, $order, $sort === 'id' ? 'p.id ASC' : 'p.last_login_at DESC NULLS LAST, p.id DESC'], $sql);
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $counts = [
            'all' => (int) $rows[0]['stats_all'], 'staff' => (int) $rows[0]['stats_staff'],
            'banned' => (int) $rows[0]['stats_banned'], 'muted' => (int) $rows[0]['stats_muted'],
            'closing' => (int) $rows[0]['stats_closing'],
        ];
        $users = [];
        foreach ($rows as $row) {
            if ($row['id'] !== null) {
                $users[] = $this->present($row);
            }
        }
        $more = count($users) > $limit;
        if ($more) {
            array_pop($users);
        }
        $last = $users === [] ? null : $users[array_key_last($users)];
        return ['users' => $users, 'counts' => $counts,
            'next_cursor' => $more ? $this->encodeCursor($last, $search, $filter, $sort) : null];
    }

    /**
     * @param array<string,mixed> $row
     * @return UserRow
     */
    private function present(array $row): array
    {
        $roles = json_decode((string) $row['roles'], true, 8, JSON_THROW_ON_ERROR);
        return [
            'id' => (int) $row['id'], 'username' => (string) $row['username'],
            'roles' => array_values(array_map(static fn (mixed $role): string => (string) $role, is_array($roles) ? $roles : [])),
            'created_at' => (string) $row['created_at'],
            'last_login_at' => $row['last_login_at'] === null ? null : (string) $row['last_login_at'],
            'active_ban' => $row['ban_starts_at'] === null ? null : [
                'reason' => (string) $row['ban_reason'],
                'expires_at' => $row['ban_expires_at'] === null ? null : (string) $row['ban_expires_at'],
                'starts_at' => (string) $row['ban_starts_at'],
                'created_by' => ['id' => (int) $row['ban_created_by'], 'username' => (string) $row['ban_creator_username']]],
            'active_mute' => $row['mute_id'] === null ? null : [
                'scope' => $row['mute_room_id'] === null ? 'everywhere' : 'room',
                'room' => $row['mute_room_id'] === null ? null : ['id' => (int) $row['mute_room_id'], 'name' => (string) $row['mute_room_name']],
                'expires_at' => $row['mute_expires_at'] === null ? null : (string) $row['mute_expires_at'],
                'room_mute_count' => (int) $row['room_count']],
            'closing' => $row['account_state'] !== 'closure_pending' ? null : ['purge_at' => (string) $row['closure_finalizes_at']],
        ];
    }

    /** @param UserRow $last */
    private function encodeCursor(array $last, string $search, ?string $filter, string $sort): string
    {
        $time = $last['last_login_at'] === null ? null
            : (new DateTimeImmutable($last['last_login_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        return rtrim(strtr(base64_encode(json_encode(['v' => 1, 'sort' => $sort, 'search' => $search,
            'filter' => $filter, 'id' => $last['id'], 'last_login_at' => $time], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{id:int,last_login_at:?string} */
    private function decodeCursor(string $cursor, string $search, ?string $filter, string $sort): array
    {
        $invalid = static fn (): ApiException => new ApiException(400, 'validation_error', 'cursor is invalid or belongs to another search, filter or sort.');
        if (strlen($cursor) > 1024 || preg_match('/\A[A-Za-z0-9_-]+\z/D', $cursor) !== 1) {
            throw $invalid();
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        try {
            $value = $decoded === false ? null : json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $invalid();
        }
        if (!is_array($value) || ($value['v'] ?? null) !== 1 || ($value['sort'] ?? null) !== $sort
            || ($value['search'] ?? null) !== $search || !array_key_exists('filter', $value) || $value['filter'] !== $filter
            || !is_int($value['id'] ?? null) || $value['id'] < 1 || !array_key_exists('last_login_at', $value)) {
            throw $invalid();
        }
        $time = $value['last_login_at'];
        if ($time !== null) {
            if (!is_string($time) || preg_match('/\A(?!0000)\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00\z/D', $time) !== 1) {
                throw $invalid();
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $time);
            if ($date === false || $date->format('Y-m-d\TH:i:s.uP') !== $time) {
                throw $invalid();
            }
        }
        return ['id' => $value['id'], 'last_login_at' => $time];
    }
}
