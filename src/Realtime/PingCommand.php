<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use ChitChat\Http\ApiException;

final class PingCommand
{
    /** @return array{username:string, message:string}|null */
    public static function parse(string $input): ?array
    {
        $body = trim($input);
        if ($body !== '/ping' && !str_starts_with($body, '/ping ')) {
            return null;
        }

        $arguments = trim(substr($body, 5));
        if ($arguments === '') {
            throw new ApiException(400, 'invalid_ping_command', 'Usage: /ping username [message].');
        }

        $parts = preg_split('/\s+/', $arguments, 2);
        if ($parts === false || $parts === []) {
            throw new ApiException(400, 'invalid_ping_command', 'Usage: /ping username [message].');
        }

        return [
            'username' => $parts[0],
            'message' => $parts[1] ?? '',
        ];
    }
}
