<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
\ChitChat\View\PolicyPage::respond($config, \ChitChat\View\PolicyLinks::PRIVACY);
