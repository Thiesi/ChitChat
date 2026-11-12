<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;
use ChitChat\Observability\SseConnectionTracker;
use ChitChat\Realtime\SseEncoder;
use ChitChat\Realtime\SseBatchGuard;
use ChitChat\Realtime\SettledStreamCursor;
use ChitChat\Realtime\StreamCursor;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';
$tracker = null;
$connectionId = null;

try {
    Request::requireMethod('GET');
    $pdo = Database::connect($config);
    $users = new UserRepository($pdo);
    $actor = SessionManager::requireUserOrGuest($users);

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

    // resume=settled (#286): the SSE id is the settled position, so a late-committing lower
    // event id is never skipped; a reconnect may resend events, which clients drop by data.id.
    $resume = Request::optionalQueryString('resume');
    if ($resume !== null && $resume !== 'settled') {
        throw new ApiException(400, 'validation_error', 'resume must be "settled".');
    }
    $settledMode = $resume === 'settled';

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    ignore_user_abort(true);
    set_time_limit(0);
    ini_set('zlib.output_compression', '0');
    $login = SessionManager::loginBinding();
    session_write_close();

    $tracker = new SseConnectionTracker($pdo, $config->sseConnectionLeaseSeconds);
    $connectionId = $tracker->open($actor->id);

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    echo "retry: 1000\n\n";
    flush();

    $cursor = new StreamCursor($pdo);
    $settled = $settledMode ? new SettledStreamCursor($pdo, $afterId) : null;
    $holderWarned = false;
    $batchGuard = new SseBatchGuard($pdo);
    // The settled-horizon check (#281) runs at most this often per connection.
    $lastSkipCheck = 0.0;
    $deadline = microtime(true) + 25.0;
    $lastHeartbeat = 0.0;
    $lastLeaseRefresh = microtime(true);

    while (microtime(true) < $deadline && connection_aborted() === 0) {
        $current = $users->findAuthenticatedById($actor->id);
        $reason = $current === null || $current->sessionVersion !== $actor->sessionVersion
            || $users->activeBan($actor->id) !== null ? 'session_invalidated'
            : ($login === null ? 'session_invalidated' : (new \ChitChat\Auth\LoginSessionRepository($pdo))->invalidReason(
                $actor->id, $actor->sessionVersion, $login['id'], $login['hash']));
        if ($reason !== null) {
            echo SseEncoder::sessionInvalidated($reason === 'session_revoked' ? 'session_revoked' : 'session_invalidated');
            flush();
            break;
        }
        if ($settled !== null) {
            // Settle every second while anything sent is unsettled, and once more before the
            // connection ends, so the reconnect resends little; otherwise as rarely as #281.
            $sinceCheck = microtime(true) - $lastSkipCheck;
            $mayCheck = $sinceCheck >= ($settled->hasUnsettled() ? 1.0 : 10.0)
                || ($settled->hasUnsettled() && $deadline - microtime(true) < 1.5 && $sinceCheck >= 0.5);
            $poll = $settled->poll($actor, 100, $mayCheck);
            $resumeIds = $poll['ids'];
            $position = $poll['settled'];
            $holder = $settled->blockingHolderStart();
            if (!$holderWarned && $holder !== null && $holder !== PHP_INT_MIN && microtime(true) * 1_000_000 - $holder > 30_000_000) {
                // Settling waits for it; nothing is skipped. idle_in_transaction_session_timeout bounds it.
                error_log(sprintf('ChitChat realtime: a transaction writing realtime_events has been open for %d s; settled stream positions wait for it.', (int) ((microtime(true) * 1_000_000 - $holder) / 1_000_000)));
                $holderWarned = true;
            }
        } else {
            $mayCheck = microtime(true) - $lastSkipCheck >= 10.0;
            $poll = $cursor->poll($actor, $afterId, 100, $mayCheck);
            $resumeIds = null;
            $position = $poll['cursor'];
        }
        if ($poll['checked']) {
            $lastSkipCheck = microtime(true);
        }
        $batch = $poll['events'];
        $checked = $batchGuard->filter($actor, $login, $batch);
        if ($checked['reason'] !== null) {
            echo SseEncoder::sessionInvalidated($checked['reason']);
            flush();
            break;
        }
        $batch = $checked['events'];
        $sentId = $afterId;
        foreach ($batch as $index => $event) {
            $sentId = $resumeIds === null ? $event->id : $resumeIds[$index];
            echo SseEncoder::event($event, $resumeIds === null ? null : $sentId);
        }
        if ($settled === null) {
            $afterId = $batch === [] ? $afterId : $sentId;
        }
        if ($position > ($settled === null ? $afterId : $sentId)) {
            // Past events this account can't see, or settled: remember it across reconnects.
            // A client that ignores this line (OkHttp's reader) only resumes from earlier.
            $afterId = $position;
            echo SseEncoder::cursor($afterId);
            flush();
        } elseif ($settled !== null) {
            $afterId = $position;
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
