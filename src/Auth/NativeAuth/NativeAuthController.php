<?php

declare(strict_types=1);

namespace ChitChat\Auth\NativeAuth;

use ChitChat\Auth\SessionManager;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;
use ChitChat\Http\SecurityHeaders;
use ChitChat\View\NativeAuthPage;

/** The native entry/callback bootstrap runs before PHP may create or replace a session. */
final class NativeAuthController
{
    public static function page(Config $config): never
    {
        self::headers($config);
        try {
            Request::requireMethod('GET');
            $service = new NativeAuthService(Database::connect($config), $config);
            $browser = $service->browser();
            if (isset($_GET['transaction_id']) || isset($_GET['entry'])) {
                $id = Request::string($_GET, 'transaction_id');
                $entry = Request::string($_GET, 'entry');
                if (!self::receivedSession($config)) {
                    $bootstrap = $browser->prepareEntryBootstrap($id, $entry);
                    NativeAuthBootstrap::send($bootstrap['cookie'], $bootstrap['remaining']);
                } else {
                    SessionManager::start($config);
                    $browser->enterWithSession($id, $entry);
                }
                self::redirect('/native-auth.php');
            }
            $cookie = self::bootstrapCookie();
            if ($cookie !== '') {
                $view = $browser->bootstrapView($cookie);
            } else {
                self::requireReceivedSession($config);
                SessionManager::start($config);
                $view = $service->browserView();
            }
            if (in_array($view['phase'], ['cancelled', 'expired', 'used', 'disabled'], true)) NativeAuthBootstrap::clear();
            header('Content-Type: text/html; charset=utf-8');
            echo NativeAuthPage::render($view);
            exit;
        } catch (\Throwable $exception) {
            self::failure($config, $exception);
        }
    }

    public static function action(Config $config, string $action): never
    {
        self::headers($config);
        try {
            Request::requireMethod('POST');
            $service = new NativeAuthService(Database::connect($config), $config);
            $browser = $service->browser();
            $csrf = Request::string($_POST, 'csrf_token');
            $cookie = self::bootstrapCookie();
            if ($cookie !== '' && in_array($action, ['continue', 'resume', 'cancel'], true)) {
                $kind = $action === 'resume' ? 'callback' : ($action === 'continue' ? 'entry' : (NativeAuthBootstrap::parse($cookie)['kind'] ?? 'entry'));
                $proof = $browser->validateBootstrap($cookie, $csrf, $kind);
                if ($kind === 'callback') self::requireReceivedSession($config);
                SessionManager::start($config);
                NativeAuthBootstrap::clear();
                $next = null;
                if ($action === 'cancel') $browser->cancelBootstrap($proof, Request::clientIp());
                elseif ($action === 'resume') $service->providerFlow()->resume($proof, Request::clientIp());
                else $next = $browser->continueBootstrap($proof, Request::clientIp());
                self::redirect($next ?? '/native-auth.php');
            }
            self::requireReceivedSession($config);
            SessionManager::start($config);
            SessionManager::requireCsrf($csrf);
            $next = null;
            match ($action) {
                'continue' => $next = $browser->continueAction(Request::clientIp()),
                'approve' => $browser->approve(Request::clientIp()),
                'cancel' => $browser->cancel(Request::clientIp()),
                'restart' => $browser->restart(Request::clientIp()),
                default => throw new ApiException(400, 'native_action_invalid', 'This action is unavailable.'),
            };
            if ($action === 'cancel') NativeAuthBootstrap::clear();
            self::redirect($next ?? '/native-auth.php');
        } catch (\Throwable $exception) {
            self::failure($config, $exception);
        }
    }

    /** Called only after config bootstrap, never after the ordinary HTTP/session bootstrap. */
    public static function callback(Config $config): never
    {
        self::headers($config);
        try {
            Request::requireMethod('GET');
            $flow = (new NativeAuthService(Database::connect($config), $config))->providerFlow();
            $flow->recognize($_GET); // Unknown/expired native state creates no session or cookie.
            if (self::receivedSession($config)) {
                SessionManager::start($config);
                $flow->direct($_GET, Request::clientIp());
                NativeAuthBootstrap::clear();
            } else {
                $bootstrap = $flow->park($_GET, Request::clientIp());
                NativeAuthBootstrap::send($bootstrap['cookie'], $bootstrap['remaining']);
            }
            self::redirect('/native-auth.php');
        } catch (\Throwable $exception) {
            self::failure($config, $exception);
        }
    }

    private static function headers(Config $config): void
    {
        SecurityHeaders::send($config, nativeAuth: true);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }

    private static function receivedSession(Config $config): bool
    {
        return is_string($_COOKIE[$config->sessionName] ?? null) && $_COOKIE[$config->sessionName] !== '';
    }

    private static function requireReceivedSession(Config $config): void
    {
        if (!self::receivedSession($config)) throw new ApiException(403, 'browser_session_changed', 'Return using the browser where this request started.');
    }

    private static function bootstrapCookie(): string
    {
        return is_string($_COOKIE[NativeAuthBootstrap::COOKIE] ?? null) ? $_COOKIE[NativeAuthBootstrap::COOKIE] : '';
    }

    private static function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    private static function failure(Config $config, \Throwable $exception): never
    {
        $status = $exception instanceof ApiException ? $exception->status : 500;
        if ($exception instanceof ApiException && $exception->retryAfterSeconds !== null) header('Retry-After: ' . $exception->retryAfterSeconds);
        if (!$exception instanceof ApiException) error_log('Native auth browser operation failed (' . $exception::class . ').');
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $phase = $exception instanceof ApiException && in_array($exception->errorCode, ['expired', 'code_expired', 'superseded'], true) ? 'expired' : 'disabled';
        try {
            $view = (new NativeAuthViewModel(Database::connect($config), $config))->model(null, $phase, null, '');
            echo NativeAuthPage::render($view);
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>Native sign-in unavailable</title><p>Start again from the app.</p>';
        }
        exit;
    }
}