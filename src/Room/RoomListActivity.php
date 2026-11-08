<?php

declare(strict_types=1);

namespace ChitChat\Room;

use ChitChat\Auth\AuthenticatedUser;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Aggregate room activity without loading identities or message contents. */
final readonly class RoomListActivity
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int, array{active_count:int, last_activity_at:?string}> keyed by readable joined room ID */
    public function forUser(AuthenticatedUser $actor): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT r.id,
       (
           SELECT COUNT(DISTINCT p.user_id)
           FROM room_presence p
           JOIN users present_user ON present_user.id = p.user_id
           WHERE p.room_id = r.id
             AND p.user_id <> :user_id
             AND p.lease_expires_at > NOW()
             AND (
                 r.inactivity_timeout_seconds = 0
                 OR p.last_interaction_at > NOW() - r.inactivity_timeout_seconds * INTERVAL '1 second'
             )
       ) AS active_count,
       (
           SELECT m.created_at
           FROM room_messages m
           WHERE m.room_id = r.id AND m.deleted_at IS NULL
           ORDER BY m.created_at DESC
           LIMIT 1
       ) AS last_activity_at
FROM room_members membership
JOIN rooms r ON r.id = membership.room_id
JOIN users viewer ON viewer.id = membership.user_id
WHERE membership.user_id = :user_id
  AND r.deleted_at IS NULL
  AND (viewer.account_kind <> 'guest' OR r.guest_access <> 'none')
  AND (
      r.minimum_age = 0
      OR CAST(:age_bypass AS integer) = 1
      OR (
          viewer.account_state = 'active'
          AND viewer.birth_date IS NOT NULL
          AND EXTRACT(YEAR FROM AGE(CAST(:today AS date), viewer.birth_date)) >= r.minimum_age
      )
  )
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare room activity aggregates.');
        }
        $statement->execute([
            'user_id' => $actor->id,
            'age_bypass' => RoomAuthorization::canModerateAnyRoom($actor) ? 1 : 0,
            // Use the same calendar day as RoomEligibility, regardless of the DB timezone.
            'today' => (new DateTimeImmutable('today'))->format('Y-m-d'),
        ]);
        $activity = [];
        foreach ($statement->fetchAll() as $row) {
            if (is_array($row)) {
                $activity[(int) $row['id']] = [
                    'active_count' => (int) $row['active_count'],
                    'last_activity_at' => $row['last_activity_at'] === null ? null
                        : (new DateTimeImmutable((string) $row['last_activity_at']))
                            ->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
                ];
            }
        }

        return $activity;
    }
}
