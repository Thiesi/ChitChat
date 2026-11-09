<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
\ChitChat\InstallationIcon\IconResponse::respond($config);
