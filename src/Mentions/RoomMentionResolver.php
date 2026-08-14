<?php

declare(strict_types=1);

namespace ChitChat\Mentions;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Auth\UserRepository;
use ChitChat\Http\ApiException;
use ChitChat\Room\Room;
use ChitChat\Room\RoomAuthorization;
use ChitChat\Room\RoomEligibility;
use ChitChat\Room\RoomRepository;
use PDO;
use RuntimeException;

final class RoomMentionResolver
{
    private readonly RoomRepository $rooms;
    private readonly RoomEligibility $eligibility;
    private readonly UserRepository $users;

    public function __construct(private readonly PDO $pdo)
    {
        $this->rooms = new RoomRepository($pdo);
        $this->eligibility = new RoomEligibility($this->rooms);
        $this->users = new UserRepository($pdo);
    }

    /**
     * Resolves @username and @room/@here tokens to currently authorized
     * accounts. An unauthorized or unresolvable token is silently dropped,
     * never surfaced as an error — mentions are best-effort, not a
     * membership-probing surface.
     *
     * @return list<array{user_id:int, broadcast:bool}>
     */
    public function resolve(int $roomId, int $senderId, string $body): array
    {
        $tokens = MentionParser::tokens($body);
        if ($tokens === []) {
            return [];
        }

        $resolved = [];
        $seenUserIds = [];
        $broadcastRequested = false;

        foreach ($tokens as $token) {
            if ($token === 'room' || $token === 'here') {
                $broadcastRequested = true;
                continue;
            }

            $userId = $this->findActiveUserIdByCanonicalUsername($token);
            if ($userId === null || $userId === $senderId || isset($seenUserIds[$userId])) {
                continue;
            }
            if (!$this->canBeMentioned($roomId, $userId)) {
                continue;
            }

            $seenUserIds[$userId] = true;
            $resolved[] = ['user_id' => $userId, 'broadcast' => false];
        }

        if ($broadcastRequested) {
            foreach ($this->rooms->memberIds($roomId) as $memberId) {
                if ($memberId === $senderId || isset($seenUserIds[$memberId])) {
                    continue;
                }
                if (!$this->canBeMentioned($roomId, $memberId)) {
                    continue;
                }
                $seenUserIds[$memberId] = true;
                $resolved[] = ['user_id' => $memberId, 'broadcast' => true];
            }
        }

        return $resolved;
    }

    private function canBeMentioned(int $roomId, int $candidateUserId): bool
    {
        $candidate = $this->users->findAuthenticatedById($candidateUserId);
        if ($candidate === null) {
            return false;
        }

        $room = $this->rooms->findForUser($roomId, $candidateUserId);
        if ($room === null || !RoomAuthorization::canReadHistory($candidate, $room)) {
            return false;
        }

        return $this->meetsMinimumAge($candidate, $room);
    }

    private function meetsMinimumAge(AuthenticatedUser $candidate, Room $room): bool
    {
        try {
            $this->eligibility->requireMinimumAge($candidate, $room);

            return true;
        } catch (ApiException) {
            return false;
        }
    }

    private function findActiveUserIdByCanonicalUsername(string $canonicalUsername): ?int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM users WHERE username_canonical = :username AND account_state = 'active'",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mention username lookup.');
        }

        $statement->execute(['username' => $canonicalUsername]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
