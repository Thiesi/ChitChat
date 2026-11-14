<?php

declare(strict_types=1);

use ChitChat\Config;
use ChitChat\Http\SecurityHeaders;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    throw new RuntimeException('Composer dependencies are not installed.');
}

require $autoload;

Config::loadEnvFile($root . '/.env');
$config = Config::fromEnvironment();
// A page that fails shows the status page, not a blank one (S7); APIs, the CLI and APP_DEBUG are untouched.
\ChitChat\View\PageErrors::install($config->debug);
SecurityHeaders::send($config);

return $config;
