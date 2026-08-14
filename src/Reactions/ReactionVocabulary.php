<?php

declare(strict_types=1);

namespace ChitChat\Reactions;

use ChitChat\Http\ApiException;

final class ReactionVocabulary
{
    /** @var list<string> */
    private const EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🎉'];

    public static function require(string $emoji): string
    {
        if (!in_array($emoji, self::EMOJI, true)) {
            throw new ApiException(400, 'invalid_reaction_emoji', 'That emoji is not part of the supported reaction set.');
        }

        return $emoji;
    }
}
