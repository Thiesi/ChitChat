<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;
use ChitChat\Observability\SseConnectionTracker;
use ChitChat\Realtime\EventRepository;
use ChitChat\Realtime\SseEncoder;
use Throwable;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';
$tracker = null;
$connectionId = null;

try {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $users = new UserRepository($pdo);
    $actor = SessionManager::requireUser($users);

    $afterId = 0;
    $lastEventHeader = trim((string) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? ''));
    if ($lastEventHeader !== '') {
        if (filter_var($lastEventHeader, FILTER_VALIDATE_INT) === false || (int) $lastEventHeader < 0) {
            throw new ApiException(400, 'validation_error', 'Last-Event-ID must not be negative.');
        }
        $afterId = (int) $lastEventHeader;
    } else {
        $afterId = Request::optionalQueryInteger('after_id') ?? 0;
        if ($afterId < 0) {
            throw new ApiException(400, 'validation_error', 'after_id must not be negative.');
        }
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    ignore_user_abort(true);
    set_time_limit(0);
    ini_set('zlib.output_compression', '0');
    session_write_close();

    $tracker = new SseConnectionTracker($pdo, $config->sseConnectionLeaseSeconds);
    $connectionId = $tracker->open($actor->id);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    echo "retry: 1000\n\n";
    flush();

    $events = new EventRepository($pdo);
    $deadline = microtime(true) + 25.0;
    $lastHeartbeat = 0.0;
    $lastLeaseRefresh = microtime(true);

    while (microtime(true) < $deadline && connection_aborted() === 0) {
        $batch = $events->visibleAfter($actor, $afterId, 100);
        foreach ($batch as $event) {
            echo SseEncoder::event($event);
            $afterId = $event->id;
        }

        $current = $users->findAuthenticatedById($actor->id);
        if (
            $current === null
            || $current->sessionVersion !== $actor->sessionVersion
            || $users->activeBan($actor->id) !== null
        ) {
            echo SseEncoder::sessionInvalidated();
            flush();
            break;
        }

        $now = microtime(true);
        if ($now - $lastLeaseRefresh >= 10.0) {
            $tracker->touch($connectionId);
            $lastLeaseRefresh = $now;
        }
        if ($batch !== [] || $now - $lastHeartbeat >= 10.0) {
            if ($batch === []) {
                echo SseEncoder::heartbeat();
            }
            flush();
            $lastHeartbeat = $now;
        }

        usleep(500_000);
    }
} catch (ApiException $exception) {
    if (!headers_sent()) {
        http_response_code($exception->status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ],
        ], JSON_UNESCAPED_SLASHES);
    } else {
        echo "event: error\ndata: {\"code\":\"stream_error\"}\n\n";
        flush();
    }
} catch (Throwable $exception) {
    error_log($exception->__toString());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":{"code":"internal_error","message":"Internal server error."}}';
    } else {
        echo "event: error\ndata: {\"code\":\"internal_error\"}\n\n";
        flush();
    }
} finally {
    if ($tracker instanceof SseConnectionTracker && is_string($connectionId)) {
        try {
            $tracker->close($connectionId);
        } catch (Throwable $exception) {
            error_log($exception->__toString());
        }
    }
}
