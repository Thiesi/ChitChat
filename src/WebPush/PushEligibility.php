<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use ChitChat\Account\IgnoreService;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\DirectMessage\DirectMessageBlockService;
use ChitChat\Http\ApiException;
use ChitChat\Moderation\MuteService;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class PushEligibility
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function suppression(PushNotification $notification, DateTimeImmutable $now): ?string
    {
        if ($notification->read) {
            return 'read';
        }
        $preferences = (new NotificationPreferenceService($this->pdo))->get($notification->userId);
        $key = ['mentioned' => 'mentioned_push_enabled', 'pinged' => 'pinged_push_enabled'][$notification->kind] ?? null;
        if ($key !== null && !$preferences[$key]) {
            return 'muted';
        }
        $quiet = $preferences['quiet_hours'];
        if ($quiet !== null && $quiet['start'] !== $quiet['end']) {
            $hour = (int) $now->setTimezone(new DateTimeZone($quiet['timezone']))->format('G');
            if ($quiet['start'] < $quiet['end']
                ? ($hour >= $quiet['start'] && $hour < $quiet['end'])
                : ($hour >= $quiet['start'] || $hour < $quiet['end'])) {
                return 'quiet_hours';
            }
        }
        $users = new UserRepository($this->pdo);
        $actor = $users->findAuthenticatedById($notification->userId);
        if ($actor === null || $actor->guest || $users->activeBan($actor->id) !== null) {
            return 'forbidden';
        }
        if (!in_array($notification->kind, ['mentioned', 'pinged'], true)) {
            return null;
        }
        $senderId = $notification->context['sender_user_id'] ?? null;
        $sender = is_int($senderId) ? $users->findAuthenticatedById($senderId) : null;
        if ($sender === null || $sender->guest || $users->activeBan($sender->id) !== null) {
            return 'forbidden';
        }
        try {
            if ($notification->kind === 'mentioned' && ($notification->context['message_kind'] ?? null) === 'direct') {
                return $this->direct($notification, $actor, $sender->id);
            }
            $roomId = $notification->context['room_id'] ?? null;
            if (!is_int($roomId) || (new IgnoreService($this->pdo))->isIgnoring($actor->id, $sender->id)) {
                return 'forbidden';
            }
            $rooms = new RoomRepository($this->pdo);
            $room = $rooms->findForUser($roomId, $actor->id);
            if ($room === null) {
                return 'forbidden';
            }
            RoomAuthorization::requireHistory($actor, $room);
            (new RoomEligibility($rooms))->requireMinimumAge($actor, $room);
            (new MuteService($this->pdo))->assertMayPostInRoom($sender->id, $roomId);
            if ($notification->kind === 'pinged') {
                return $this->exists(<<<'SQL'
SELECT 1 FROM room_pings WHERE id = :id AND room_id = :room AND target_id = :target AND sender_id = :sender
SQL, ['id' => $notification->context['ping_id'] ?? 0, 'room' => $roomId, 'target' => $actor->id,
                    'sender' => $sender->id]) ? null : 'forbidden';
            }
            return $this->exists(<<<'SQL'
SELECT 1 FROM room_messages m WHERE m.id = :id AND m.room_id = :room AND m.sender_id = :sender
 AND m.deleted_at IS NULL
 AND NOT EXISTS (SELECT 1 FROM attachments a WHERE a.message_id = m.id AND a.deleted_at IS NOT NULL)
SQL, ['id' => $notification->context['message_id'] ?? 0, 'room' => $roomId, 'sender' => $sender->id])
                ? null : 'forbidden';
        } catch (ApiException $exception) {
            if ($exception->status !== 403 && $exception->status !== 404) {
                throw $exception;
            }
            return 'forbidden';
        }
    }

    private function direct(PushNotification $notification, AuthenticatedUser $actor, int $senderId): ?string
    {
        if (!(new DirectMessageBlockService($this->pdo))->relationship($actor, $senderId)['messaging_available']) {
            return 'forbidden';
        }
        (new MuteService($this->pdo))->assertMaySendDirect($senderId);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT recipient_read_at FROM direct_messages dm
WHERE id = :id AND sender_user_id = :sender AND recipient_user_id = :recipient AND deleted_at IS NULL
 AND NOT EXISTS (SELECT 1 FROM direct_message_attachments a WHERE a.direct_message_id = dm.id AND a.deleted_at IS NOT NULL)
SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push conversation eligibility.');
        }
        $statement->execute(['id' => $notification->context['message_id'] ?? 0, 'sender' => $senderId, 'recipient' => $actor->id]);
        $row = $statement->fetch();
        return !is_array($row) ? 'forbidden' : ($row['recipient_read_at'] !== null ? 'read' : null);
    }

    /** @param array<string,mixed> $parameters */
    private function exists(string $sql, array $parameters): bool
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare push message eligibility.');
        }
        $statement->execute($parameters);
        return $statement->fetchColumn() !== false;
    }
}
