<?php

declare(strict_types=1);

use ChitChat\Auth\NativeAndroidApps;

// This bootstrap loads configuration and security headers, never a session or database.
try {
    /** @var ChitChat\Config $config */
    $config = require dirname(__DIR__) . '/bootstrap/app.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD');
        header('Cache-Control: no-store');
        echo '{"error":{"code":"method_not_allowed","message":"Use GET or HEAD."}}';
        exit;
    }
    $json = json_encode(NativeAndroidApps::assetLinks($config->nativeAndroidApps),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    header('Cache-Control: public, max-age=300');
    header('Content-Length: ' . strlen($json));
    if ($method !== 'HEAD') {
        echo $json;
    }
} catch (Throwable) {
    error_log('Unable to generate the Android credential association document.');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo '{"error":{"code":"internal_error","message":"The association document is unavailable."}}';
    }
}
