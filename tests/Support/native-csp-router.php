<?php

declare(strict_types=1);

// Test-only HTTP router, outside public/. It sends the production header builder.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = \ChitChat\Config::fromEnvironment();
\ChitChat\Http\SecurityHeaders::send($config, nativeAuth: str_starts_with($_SERVER['REQUEST_URI'], '/native'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: ' . getenv('OIDC_GOOGLE_ENDPOINT_OVERRIDE') . '/authorize', true, 303);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><form method="post"><button>Continue</button></form>';