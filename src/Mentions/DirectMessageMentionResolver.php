<?php

declare(strict_types=1);

namespace ChitChat\Mentions;

use ChitChat\Auth\Username;

final class DirectMessageMentionResolver
{
    /**
     * A direct message has exactly one other participant, so the only
     * account that can ever be mentioned is the recipient. @room/@here
     * tokens have no meaning here and are left as plain text.
     *
     * @return list<array{user_id:int, broadcast:bool}>
     */
    public static function resolve(int $recipientUserId, string $recipientUsername, string $body): array
    {
        $recipientCanonical = Username::canonical($recipientUsername);
        foreach (MentionParser::tokens($body) as $token) {
            if ($token === $recipientCanonical) {
                return [['user_id' => $recipientUserId, 'broadcast' => false]];
            }
        }

        return [];
    }
}
