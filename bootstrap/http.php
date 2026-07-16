<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;

/** @var ChitChat\Config $config */
$config = require __DIR__ . '/app.php';
SessionManager::start($config);

return $config;
