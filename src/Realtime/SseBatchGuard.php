<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\LoginSessionRepository;
use PDO;

/** Final authorization boundary between fetching an SSE batch and writing it. */
final readonly class SseBatchGuard
{
    public function __construct(private PDO $pdo) {}

    /** @param array{id:string,hash:string}|null $login Server-only holder proof.
     * @param list<RealtimeEvent> $events
     * @return array{events:list<RealtimeEvent>,reason:('session_revoked'|'session_invalidated')|null}
     */
    public function filter(AuthenticatedUser $actor, ?array $login, array $events): array
    {
        // Fetching may race a committed revocation. Do not emit even the already
        // fetched events once this holder is invalid; never invalidate other logins.
        $reason = $login === null ? 'authentication_required'
            : (new LoginSessionRepository($this->pdo))->invalidReason(
                $actor->id, $actor->sessionVersion, $login['id'], $login['hash']);
        if ($reason !== null) {
            return ['events' => [], 'reason' => $reason === 'session_revoked' ? 'session_revoked' : 'session_invalidated'];
        }
        return ['events' => $events, 'reason' => null];
    }
}