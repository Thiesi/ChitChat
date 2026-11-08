<?php

declare(strict_types=1);

namespace ChitChat\Auth;

final readonly class AuthenticatedUser
{
    /**
     * @param list<string> $roles
     * @param array{accepted_version:int,accepted_at:?string,acceptance_required:bool} $rules
     * @param array{exists:bool,created_at:?string}|null $recoveryKey
     */
    public function __construct(
        public int $id,
        public string $username,
        public array $roles,
        public int $sessionVersion,
        // A guest looks around without an account; see migrations/0039_guest_access.sql.
        public bool $guest = false,
        public ?string $guestExpiresAt = null,
        public array $rules = ['accepted_version' => 0, 'accepted_at' => null, 'acceptance_required' => false],
        public ?array $recoveryKey = null,
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

    /** @return array{id:int, username:string, roles:list<string>, session_version:int, guest:bool, guest_expires_at:?string, rules:array{accepted_version:int,accepted_at:?string,acceptance_required:bool}, recovery_key:array{exists:bool,created_at:?string}|null} */
    public function toSessionArray(): array
    {
        return [
            ...$this->toArray(),
            'session_version' => $this->sessionVersion,
            'guest' => $this->guest,
            'guest_expires_at' => $this->guestExpiresAt,
            'rules' => $this->rules,
            'recovery_key' => $this->recoveryKey,
        ];
    }
}
