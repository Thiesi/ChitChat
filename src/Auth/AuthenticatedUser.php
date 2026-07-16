<?php

declare(strict_types=1);

namespace ChitChat\Auth;

final readonly class AuthenticatedUser
{
    /** @param list<string> $roles */
    public function __construct(
        public int $id,
        public string $username,
        public array $roles,
        public int $sessionVersion,
    ) {
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function canManageUsers(): bool
    {
        return $this->hasRole('super_admin') || $this->hasRole('admin');
    }

    /** @return array{id:int, username:string, roles:list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'roles' => $this->roles,
        ];
    }
}
