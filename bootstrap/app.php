<?php

declare(strict_types=1);

use ChitChat\Config;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    throw new RuntimeException('Composer dependencies are not installed.');
}

require $autoload;

Config::loadEnvFile($root . '/.env');

return Config::fromEnvironment();
