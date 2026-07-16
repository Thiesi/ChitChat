<?php

declare(strict_types=1);

namespace ChitChat\Http;

use JsonException;

final class JsonResponse
{
    /**
     * @param array<string, mixed> $payload
     * @throws JsonException
     */
    public static function send(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
