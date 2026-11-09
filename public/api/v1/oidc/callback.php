<?php

declare(strict_types=1);

use ChitChat\Auth\NativeAuth\NativeAuthController;
use ChitChat\Auth\Oidc\OidcRedirectException;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Auth\SessionManager;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/app.php';

// Native recognition must precede PHP session startup, including unknown/expired native states.
if (is_string($_GET['state'] ?? null) && str_starts_with($_GET['state'], 'native.')) {
    NativeAuthController::callback($config);
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$send = static function (string $path, ?string $message = null): never {
    if ($message !== null) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'sign_in_error=' . rawurlencode($message);
    }
    header('Location: ' . $path, true, 302);
    exit;
};

try {
    Request::requireMethod('GET');
    // Ordinary web states are minted from 32 random bytes. Reject malformed input
    // before opening a session; only the original session can validate ownership.
    $state = $_GET['state'] ?? null;
    if (!is_string($state) || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $state) !== 1) {
        throw new ApiException(400, 'oidc_flow_expired', 'This sign-in attempt has expired or was already used. Please start again.');
    }
    $cookie = $_COOKIE[$config->sessionName] ?? null;
    if (!is_string($cookie) || $cookie === '') {
        // A Strict cookie may be withheld on the provider's cross-site return.
        // Do not start PHP here: its replacement SID would overwrite the original.
        // A document navigation can recover that cookie; an HTTP redirect cannot.
        if (isset($_GET['bounced'])) {
            throw new ApiException(400, 'oidc_flow_expired', 'This sign-in attempt has expired or was already used. Please start again.');
        }
        $query = array_intersect_key($_GET, array_flip(['state', 'code', 'error', 'error_description']));
        $again = '/api/v1/oidc/callback.php?' . http_build_query([...$query, 'bounced' => '1']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='
            . htmlspecialchars($again, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"><title>Signing in…</title><p>Signing in…</p>';
        exit;
    }
    SessionManager::start($config);
    $next = (new OidcService(Database::connect($config), $config))->complete($_GET, Request::clientIp());
    $send($next);
} catch (OidcRedirectException $exception) {
    $send($exception->returnTo, $exception->getMessage());
} catch (ApiException $exception) {
    $send('/', $exception->getMessage());
} catch (Throwable $exception) {
    error_log($exception->__toString());
    $send('/', 'Signing in failed. Please try again.');
}
