<?php

declare(strict_types=1);

namespace ChitChat\Http;

use ChitChat\Config;
use JsonException;
use Throwable;

final class Endpoint
{
    /** @param callable(): ApiResult $handler */
    public static function run(Config $config, callable $handler): never
    {
        try {
            $result = $handler();
            JsonResponse::send($result->payload, $result->status);
        } catch (ApiException $exception) {
            JsonResponse::send([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status);
        } catch (JsonException $exception) {
            error_log($exception->getMessage());
            JsonResponse::send([
                'error' => [
                    'code' => 'invalid_json',
                    'message' => 'The request or response JSON is invalid.',
                ],
            ], 400);
        } catch (Throwable $exception) {
            error_log($exception->__toString());
            $message = $config->debug ? $exception->getMessage() : 'An internal server error occurred.';
            JsonResponse::send([
                'error' => [
                    'code' => 'internal_error',
                    'message' => $message,
                ],
            ], 500);
        }
    }
}
