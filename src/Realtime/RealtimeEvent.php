<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

final readonly class RealtimeEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $type,
        public ?int $roomId,
        public ?int $targetUserId,
        public ?int $actorUserId,
        public array $payload,
        public string $createdAt,
    ) {
    }

    /** @return array{id:int, type:string, room_id:?int, actor_user_id:?int, payload:array<string, mixed>, created_at:string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'room_id' => $this->roomId,
            'actor_user_id' => $this->actorUserId,
            'payload' => $this->payload,
            'created_at' => $this->createdAt,
        ];
    }
}
