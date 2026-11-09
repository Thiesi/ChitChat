<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\JsonResponse;
use ChitChat\Http\Request;

/** Authentication credentials must never reach exception traces or debug error bodies. */
final class NativeAuthEndpoint
{
    public static function run(Config $config, string $action): never
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        try {
            Request::requireMethod($action === 'status' ? 'GET' : 'POST');
            if ($action !== 'status') SessionManager::requireCsrf(Request::csrfHeader());
            $service = new NativeAuthService(Database::connect($config), $config);
            $payload = $action === 'status' ? $_GET : Request::json();
            $ip = Request::clientIp();
            $result = match ($action) {
                'start' => $service->start($payload, $ip),
                'exchange' => $service->exchange($payload, $ip),
                'cancel' => $service->cancel(Request::string($payload, 'transaction_id'), $ip),
                'status' => $service->status(Request::string($payload, 'transaction_id'), $ip),
                default => throw new ApiException(404, 'not_found', 'This operation is unavailable.'),
            };
            JsonResponse::send($result, in_array($result['outcome'] ?? null, ['pending_mfa', 'pending_sign_up'], true) && $action === 'exchange' ? 202 : 200);
        } catch (ApiException $exception) {
            JsonResponse::send(['error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage(),
                ...($exception->status === 429 && $exception->retryAfterSeconds !== null ? ['retry_after_seconds' => $exception->retryAfterSeconds] : [])]],
                $exception->status, $exception->retryAfterSeconds);
        } catch (\JsonException) {
            JsonResponse::send(['error' => ['code' => 'invalid_json', 'message' => 'The JSON request is invalid.']], 400);
        } catch (\Throwable $exception) {
            error_log('Native auth API operation failed (' . $exception::class . ').');
            JsonResponse::send(['error' => ['code' => 'internal_error', 'message' => 'Unable to complete this request. Check the app before starting again.']], 500);
        }
    }
}