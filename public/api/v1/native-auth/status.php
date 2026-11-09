<?php

declare(strict_types=1);

use ChitChat\Auth\NativeAuth\NativeAuthEndpoint;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';
NativeAuthEndpoint::run($config, 'status');