<?php

declare(strict_types=1);

namespace ChitChat\Reactions;

final class ReactionHydrator
{
    /**
     * Builds a correlated subquery (aliased `reactions_json` by the caller)
     * that groups a message's reaction rows by emoji, each carrying its
     * reactor list and whether $viewerParameter (a bound PDO parameter name,
     * e.g. ':viewer_user_id') is among them. $messageIdExpression is the
     * outer row's message id column (e.g. 'm.id') for a correlated read, or
     * a bound parameter (e.g. ':message_id') for a standalone lookup.
     */
    public static function correlatedSubquery(
        string $reactionsTable,
        string $messageIdExpression,
        string $viewerParameter,
    ): string {
        return <<<SQL
(
    SELECT json_agg(
        json_build_object(
            'emoji', grouped.emoji,
            'users', grouped.users,
            'reacted_by_me', grouped.reacted_by_me
        )
        ORDER BY grouped.first_id
    )
    FROM (
        SELECT r.emoji,
               MIN(r.id) AS first_id,
               json_agg(json_build_object('id', ru.id, 'username', ru.username) ORDER BY r.id) AS users,
               bool_or(r.user_id = {$viewerParameter}) AS reacted_by_me
        FROM {$reactionsTable} r
        JOIN users ru ON ru.id = r.user_id
        WHERE r.message_id = {$messageIdExpression}
        GROUP BY r.emoji
    ) grouped
)
SQL;
    }

    /**
     * Decodes the `reactions_json` column produced by the shared correlated
     * subquery (grouped by emoji, each carrying its reactor list and whether
     * the query's bound viewer is among them) into the API-facing shape.
     *
     * @return list<array{emoji:string, users:list<array{id:int, username:string}>, reacted_by_me:bool}>
     */
    public static function hydrateJson(mixed $encoded): array
    {
        if (!is_string($encoded) || $encoded === '') {
            return [];
        }

        $decoded = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        $reactions = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !is_array($entry['users'] ?? null)) {
                continue;
            }
            $users = [];
            foreach ($entry['users'] as $user) {
                if (!is_array($user)) {
                    continue;
                }
                $users[] = [
                    'id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                ];
            }
            $reactions[] = [
                'emoji' => (string) $entry['emoji'],
                'users' => $users,
                'reacted_by_me' => $entry['reacted_by_me'] === true,
            ];
        }

        return $reactions;
    }
}
