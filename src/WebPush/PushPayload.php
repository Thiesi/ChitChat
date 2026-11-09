<?php

declare(strict_types=1);

namespace ChitChat\WebPush;

use LengthException;
use InvalidArgumentException;

final class PushPayload
{
    public const MAX_BYTES = 2999;

    /** @param array{title:string,message:string,link:?string,details:list<string>} $rendered */
    public static function build(PushNotification $notification, array $rendered, string $applicationName,
        string $eventId, ?string $binding): string
    {
        foreach ([$eventId, $binding] as $identifier) {
            if ($identifier !== null && preg_match('/\A[\x21-\x7e]{1,64}\z/D', $identifier) !== 1) {
                throw new InvalidArgumentException('Invalid push identifier.');
            }
        }
        if ($notification->id < 1) {
            throw new InvalidArgumentException('Invalid push notification ID.');
        }
        $payload = ['title' => $rendered['title'], 'body' => $rendered['message'],
            'link' => $rendered['link'], 'app_name' => $applicationName,
            'kind' => $notification->kind, 'notification_id' => $notification->id, 'event_id' => $eventId];
        if ($binding !== null) {
            $payload['binding'] = $binding;
        }
        // Encoding changes no field semantics; UTF-8 avoids needlessly expanding names to JSON escapes.
        $encode = static fn (): string => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $encode();
        foreach (['body', 'title', 'app_name'] as $field) {
            if (strlen($json) <= self::MAX_BYTES) {
                break;
            }
            $excess = strlen($json) - self::MAX_BYTES;
            $length = strlen($payload[$field]);
            $keep = max(0, $length - $excess - 3);
            $payload[$field] = $keep === 0 ? '' : mb_strcut($payload[$field], 0, $keep, 'UTF-8') . '…';
            // Rebuild after each bounded reduction; identifier/link values are never shortened.
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new LengthException('Push payload exceeds the safe plaintext budget.');
        }
        return $json;
    }
}
