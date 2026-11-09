<?php

declare(strict_types=1);

use ChitChat\Auth\NativeAuth\NativeAuthController;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 5) . '/bootstrap/app.php';
NativeAuthController::action($config, 'restart');