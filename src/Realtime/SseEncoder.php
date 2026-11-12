<?php

declare(strict_types=1);

namespace ChitChat\Realtime;

use JsonException;
use RuntimeException;

final class SseEncoder
{
    public static function event(RealtimeEvent $event): string
    {
        try {
            $data = json_encode($event->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Server-Sent Event.', 0, $exception);
        }

        return sprintf(
            "id: %d\nevent: %s\ndata: %s\n\n",
            $event->id,
            $event->type,
            $data,
        );
    }

    /**
     * Moves the client's Last-Event-ID without dispatching anything (#281): an SSE message
     * with an id and no data updates the last event ID but fires no event.
     */
    public static function cursor(int $id): string
    {
        return sprintf("id: %d\n\n", $id);
    }

    public static function heartbeat(): string
    {
        return ': heartbeat ' . gmdate(DATE_ATOM) . "\n\n";
    }

    public static function sessionInvalidated(string $reason = 'session_invalidated'): string
    {
        return "event: forced_logout\ndata: " . json_encode(['reason' => $reason], JSON_THROW_ON_ERROR) . "\n\n";
    }
}
