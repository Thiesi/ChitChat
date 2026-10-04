<?php

declare(strict_types=1);

use ChitChat\Auth\Oidc\OidcService;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\Request;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

// "Continue with Google/Twitch" on the sign-in page: a plain link that sends the
// browser to the provider. The state bound to this session prevents forgery.
try {
    Request::requireMethod('GET');
    $provider = is_string($_GET['provider'] ?? null) ? $_GET['provider'] : '';
    $url = (new OidcService(Database::connect($config), $config))->begin($provider, 'login', null);
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
} catch (ApiException $exception) {
    header('Location: /?sign_in_error=' . rawurlencode($exception->getMessage()), true, 302);
}
exit;
