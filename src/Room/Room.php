<?php

declare(strict_types=1);

namespace ChitChat\Room;

final readonly class Room
{
    public function __construct(
        public int $id,
        public string $key,
        public string $name,
        public string $infoLine,
        public string $visibility,
        public int $minimumAge,
        public int $createdBy,
        public ?string $memberRole,
        public bool $invited,
    ) {
    }

    public function isMember(): bool
    {
        return $this->memberRole !== null;
    }

    /** @return array{id:int, key:string, name:string, info_line:string, visibility:string, minimum_age:int, created_by:int, member_role:?string, invited:bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'info_line' => $this->infoLine,
            'visibility' => $this->visibility,
            'minimum_age' => $this->minimumAge,
            'created_by' => $this->createdBy,
            'member_role' => $this->memberRole,
            'invited' => $this->invited,
        ];
    }
}
