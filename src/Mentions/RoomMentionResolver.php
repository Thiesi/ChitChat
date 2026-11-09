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

        $candidates = $this->candidateUsers($tokens);
        $eligibility = [];
        // Authorize lazily in longest-first order, memoized across all tokens.
        // Shorter real names need no history/age queries once a longer name wins.
        $canMention = function (string $name) use ($candidates, $roomId, $senderId, &$eligibility): bool {
            if (!isset($candidates[$name])) return false;
            return $eligibility[$name] ??= $candidates[$name] !== $senderId && $this->canBeMentioned($roomId, $candidates[$name]);
        };
        $resolved = [];
        $seenUserIds = [];
        $broadcastRequested = false;

        foreach ($tokens as $raw) {
            $token = MentionParser::resolveToken($raw, $canMention, true);
            if ($token === null) continue;
            if ($token === 'room' || $token === 'here') {
                $broadcastRequested = true;
                continue;
            }

            $userId = $candidates[$token];
            if (isset($seenUserIds[$userId])) {
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
        // Guests are never mentioned; they only look around.
        if ($candidate === null || $candidate->guest) {
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

    /** Batch the spelling lookup; the resolver authorizes candidates lazily using existing history/age rules.
     * @param list<string> $tokens
     * @return array<array-key,int>
     */
    private function candidateUsers(array $tokens): array
    {
        $names = [];
        foreach ($tokens as $token) {
            foreach (MentionParser::candidates($token) as $candidate) {
                if ($candidate !== 'room' && $candidate !== 'here') {
                    $names[$candidate] = true;
                }
            }
        }
        if ($names === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT id, username_canonical FROM users WHERE username_canonical IN ('
            . implode(',', array_fill(0, count($names), '?'))
            . ") AND account_state = 'active' AND account_kind = 'member'",
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to prepare mention username lookup.');
        }
        $statement->execute(array_keys($names));
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $id = (int) $row['id'];
            $result[(string) $row['username_canonical']] = $id;
        }
        return $result;
    }
}
