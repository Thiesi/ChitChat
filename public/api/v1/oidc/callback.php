<?php

declare(strict_types=1);

use ChitChat\Auth\Oidc\OidcRedirectException;
use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

// With SESSION_COOKIE_SAMESITE=Strict the browser withholds the session cookie
// on the provider's cross-site redirect. Reloading the same URL from this page
// is a same-site navigation, so the cookie comes along the second time.
if (!isset($_SESSION['oidc_flows']) && !isset($_GET['bounced'])) {
    $again = '/api/v1/oidc/callback.php?' . http_build_query([...$_GET, 'bounced' => '1']);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='
        . htmlspecialchars($again, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '"><title>Signing in…</title><p>Signing in…</p>';
    exit;
}

$send = static function (string $path, ?string $message = null): never {
    if ($message !== null) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'sign_in_error=' . rawurlencode($message);
    }
    header('Location: ' . $path, true, 302);
    exit;
};

try {
    Request::requireMethod('GET');
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
