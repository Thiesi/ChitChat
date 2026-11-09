<?php

declare(strict_types=1);

// Test-only provider with signed ID tokens; never loaded by public/.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$path = (string) getenv('CC_NATIVE_PROVIDER_FIXTURE');
$state = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
$provider = new \ChitChat\Tests\Support\NativeOidcProvider($state['issuer'], $state['pem']);
$provider->nonce = $state['nonce'] ?? '';
$provider->challenge = $state['challenge'] ?? '';
$provider->subject = $state['subject'] ?? 'subject';
$provider->authTime = $state['auth_time'] ?? null;
$provider->pictureClaim = ($state['picture'] ?? false) ? $state['issuer'] . '/picture' : null;
header('Content-Type: application/json');
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/authorize') {
    $state['nonce'] = $_GET['nonce'];
    $state['challenge'] = $_GET['code_challenge'];
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode(['state' => $_GET['state'], 'code' => 'fixture-provider-code'], JSON_THROW_ON_ERROR);
} elseif ($route === '/keys') {
    echo json_encode($provider->getJson($state['issuer'] . '/keys'), JSON_THROW_ON_ERROR);
} elseif ($route === '/token') {
    echo json_encode($provider->postForm($state['issuer'] . '/token', $_POST), JSON_THROW_ON_ERROR);
} elseif ($route === '/picture') {
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxS8AAAAASUVORK5CYII=', true);
} else {
    http_response_code(404);
    echo '{}';
}