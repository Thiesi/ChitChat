<?php

declare(strict_types=1);

// The development server's handling of missing .ico files differs by PHP version.
// Model the deployed Nginx try_files / Apache FallbackResource dispatch explicitly.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/favicon.ico') {
    require dirname(__DIR__, 2) . '/public/index.php';
    return true;
}
return false;
