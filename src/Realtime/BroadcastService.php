<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Audit\AuditLogger;
use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Http\ApiException;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

final class BroadcastService
{
    private readonly RoomRepository $rooms;
    private readonly EventRepository $events;
    private readonly AuditLogger $audit;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->events = new EventRepository($pdo);
        $this->audit = new AuditLogger($pdo);
    }

    public function global(
        AuthenticatedUser $actor,
        string $messageInput,
        string $ipAddress,
    ): RealtimeEvent {
        if (
            !$actor->hasRole('super_admin')
            && !$actor->hasRole('admin')
            && !$actor->hasRole('chat_admin')
        ) {
            throw new ApiException(403, 'permission_denied', 'Global broadcast permission is required.');
        }

        $message = $this->validateMessage($messageInput);
        $this->pdo->beginTransaction();
        try {
            $event = $this->events->publish(
                type: 'global_broadcast',
                payload: [
                    'sender' => $actor->toArray(),
                    'message' => $message,
                ],
                actorUserId: $actor->id,
                expiresAt: new DateTimeImmutable('+1 day'),
            );
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'realtime.global_broadcast',
                subjectType: 'realtime_event',
                subjectId: (string) $event->id,
                metadata: ['message' => $message],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();

            return $event;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function room(
        AuthenticatedUser $actor,
        int $roomId,
        string $messageInput,
        string $ipAddress,
    ): RealtimeEvent {
        if ($roomId < 1) {
            throw new ApiException(400, 'validation_error', 'room_id must be positive.');
        }
        $room = $this->rooms->findForUser($roomId, $actor->id);
        if ($room === null) {
            throw new ApiException(404, 'room_not_found', 'Room not found.');
        }
        RoomAuthorization::requireModerate($actor, $room);
        $message = $this->validateMessage($messageInput);

        $this->pdo->beginTransaction();
        try {
            $event = $this->events->publish(
                type: 'room_broadcast',
                payload: [
                    'room_id' => $roomId,
                    'sender' => $actor->toArray(),
                    'message' => $message,
                ],
                roomId: $roomId,
                actorUserId: $actor->id,
                expiresAt: new DateTimeImmutable('+1 day'),
            );
            $this->audit->log(
                actorUserId: $actor->id,
                action: 'realtime.room_broadcast',
                subjectType: 'realtime_event',
                subjectId: (string) $event->id,
                metadata: ['room_id' => $roomId, 'message' => $message],
                ipAddress: $ipAddress,
            );
            $this->pdo->commit();

            return $event;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function validateMessage(string $messageInput): string
    {
        $message = trim($messageInput);
        if ($message === '') {
            throw new ApiException(400, 'empty_broadcast', 'Broadcast text cannot be empty.');
        }
        if (mb_strlen($message, 'UTF-8') > 1000) {
            throw new ApiException(400, 'broadcast_too_long', 'Broadcast text must not exceed 1000 characters.');
        }

        return $message;
    }
}
