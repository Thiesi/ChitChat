<?php

declare(strict_types=1);

namespace ChitChat\Search;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class MessageSearchService
{
    private readonly RoomRepository $rooms;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
    }

    /**
     * @return array{
     *   results:list<array{
     *     kind:string,
     *     message_id:int,
     *     excerpt:string,
     *     sender:?array{id:int, username:string},
     *     room:?array{id:int, key:string, name:string},
     *     peer:?array{id:int, username:string},
     *     outgoing:?bool,
     *     created_at:string,
     *     edited_at:?string
     *   }>,
     *   has_more:bool,
     *   next_offset:?int
     * }
     */
    public function search(
        AuthenticatedUser $actor,
        string $queryInput,
        string $scope = 'all',
        int $limit = 25,
        int $offset = 0,
    ): array {
        $query = preg_replace('/\s+/u', ' ', trim($queryInput));
        if (!is_string($query)) {
            $query = trim($queryInput);
        }
        $length = mb_strlen($query, 'UTF-8');
        if ($length < 2 || $length > 200) {
            throw new ApiException(400, 'validation_error', 'query must contain 2-200 characters.');
        }
        if (preg_match('/[\p{L}\p{N}]/u', $query) !== 1) {
            throw new ApiException(400, 'validation_error', 'query must contain a letter or number.');
        }
        if (!in_array($scope, ['all', 'rooms', 'direct'], true)) {
            throw new ApiException(400, 'validation_error', 'scope must be all, rooms, or direct.');
        }
        if ($limit < 1 || $limit > 50) {
            throw new ApiException(400, 'validation_error', 'limit must be between 1 and 50.');
        }
        if ($offset < 0 || $offset > 5_000) {
            throw new ApiException(400, 'validation_error', 'offset must be between 0 and 5000.');
        }

        $parts = [];
        $bindings = ['search_query' => $query];
        if ($scope !== 'direct') {
            $canModerateEveryRoom = RoomAuthorization::canModerateAnyRoom($actor);
            $parts[] = <<<'SQL'
SELECT 'room'::text AS kind,
       m.id AS message_id,
       m.body,
       m.created_at,
       m.edited_at,
       sender.id AS sender_id,
       sender.username AS sender_username,
       r.id AS room_id,
       r.room_key,
       r.name AS room_name,
       CAST(NULL AS bigint) AS peer_id,
       CAST(NULL AS varchar) AS peer_username,
       CAST(NULL AS integer) AS outgoing,
       ts_rank_cd(to_tsvector('simple', m.body), search_query.query) AS relevance
FROM room_messages m
JOIN rooms r ON r.id = m.room_id
LEFT JOIN users sender ON sender.id = m.sender_id
LEFT JOIN room_members membership
       ON membership.room_id = r.id AND membership.user_id = :room_actor_id
LEFT JOIN room_invitations invitation
       ON invitation.room_id = r.id AND invitation.user_id = :room_invited_actor_id
CROSS JOIN search_query
WHERE m.deleted_at IS NULL
  AND r.deleted_at IS NULL
  AND to_tsvector('simple', m.body) @@ search_query.query
  AND (
      CAST(:can_moderate_every_room AS integer) = 1
      OR r.visibility = 'public'
      OR membership.user_id IS NOT NULL
      OR (r.visibility = 'unlisted' AND invitation.user_id IS NOT NULL)
  )
  AND (
      CAST(:can_bypass_minimum_age AS integer) = 1
      OR r.minimum_age = 0
      OR COALESCE(CAST(:actor_age AS integer), -1) >= r.minimum_age
  )
SQL;
            $bindings['room_actor_id'] = $actor->id;
            $bindings['room_invited_actor_id'] = $actor->id;
            $bindings['can_moderate_every_room'] = $canModerateEveryRoom ? 1 : 0;
            $bindings['can_bypass_minimum_age'] = $canModerateEveryRoom ? 1 : 0;
            $bindings['actor_age'] = $this->actorAge($actor);
        }

        if ($scope !== 'rooms') {
            $parts[] = <<<'SQL'
SELECT 'direct'::text AS kind,
       dm.id AS message_id,
       dm.body,
       dm.created_at,
       dm.edited_at,
       sender.id AS sender_id,
       sender.username AS sender_username,
       CAST(NULL AS bigint) AS room_id,
       CAST(NULL AS varchar) AS room_key,
       CAST(NULL AS varchar) AS room_name,
       peer.id AS peer_id,
       peer.username AS peer_username,
       (dm.sender_user_id = :direct_outgoing_actor_id)::int AS outgoing,
       ts_rank_cd(to_tsvector('simple', dm.body), search_query.query) AS relevance
FROM direct_messages dm
JOIN users sender ON sender.id = dm.sender_user_id
JOIN users peer ON peer.id = CASE
    WHEN dm.sender_user_id = :direct_peer_actor_id THEN dm.recipient_user_id
    ELSE dm.sender_user_id
END
CROSS JOIN search_query
WHERE dm.deleted_at IS NULL
  AND (
      dm.sender_user_id = :direct_sender_actor_id
      OR dm.recipient_user_id = :direct_recipient_actor_id
  )
  AND to_tsvector('simple', dm.body) @@ search_query.query
SQL;
            $bindings['direct_outgoing_actor_id'] = $actor->id;
            $bindings['direct_peer_actor_id'] = $actor->id;
            $bindings['direct_sender_actor_id'] = $actor->id;
            $bindings['direct_recipient_actor_id'] = $actor->id;
        }

        $sql = sprintf(
            <<<'SQL'
WITH search_query AS (
    SELECT websearch_to_tsquery('simple', :search_query) AS query
)
SELECT *
FROM (
%s
) searchable_messages
ORDER BY relevance DESC, created_at DESC, kind, message_id DESC
LIMIT :result_limit
OFFSET :result_offset
SQL,
            implode("\nUNION ALL\n", $parts),
        );

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare message search.');
        }
        foreach ($bindings as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR),
            );
        }
        $statement->bindValue(':result_limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':result_offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $results = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $results[] = $this->hydrate($row);
            }
        }

        return [
            'results' => $results,
            'has_more' => $hasMore,
            'next_offset' => $hasMore ? $offset + count($results) : null,
        ];
    }

    private function actorAge(AuthenticatedUser $actor): ?int
    {
        $birthDate = $this->rooms->birthDateForUser($actor->id);
        if ($birthDate === null) {
            return null;
        }

        return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable('today'))->y;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   kind:string,
     *   message_id:int,
     *   excerpt:string,
     *   sender:?array{id:int, username:string},
     *   room:?array{id:int, key:string, name:string},
     *   peer:?array{id:int, username:string},
     *   outgoing:?bool,
     *   created_at:string,
     *   edited_at:?string
     * }
     */
    private function hydrate(array $row): array
    {
        $body = preg_replace('/\s+/u', ' ', trim((string) $row['body']));
        if (!is_string($body)) {
            $body = trim((string) $row['body']);
        }
        $excerpt = mb_strlen($body, 'UTF-8') > 320
            ? rtrim(mb_substr($body, 0, 319, 'UTF-8')) . '…'
            : $body;
        $kind = (string) $row['kind'];

        return [
            'kind' => $kind,
            'message_id' => (int) $row['message_id'],
            'excerpt' => $excerpt,
            'sender' => $row['sender_id'] === null ? null : [
                'id' => (int) $row['sender_id'],
                'username' => (string) $row['sender_username'],
            ],
            'room' => $kind !== 'room' ? null : [
                'id' => (int) $row['room_id'],
                'key' => (string) $row['room_key'],
                'name' => (string) $row['room_name'],
            ],
            'peer' => $kind !== 'direct' ? null : [
                'id' => (int) $row['peer_id'],
                'username' => (string) $row['peer_username'],
            ],
            'outgoing' => $kind !== 'direct' ? null : (int) $row['outgoing'] === 1,
            'created_at' => (string) $row['created_at'],
            'edited_at' => $row['edited_at'] === null ? null : (string) $row['edited_at'],
        ];
    }
}
