<?php

declare(strict_types=1);

// For an operator's ErrorDocument 404 / error_page 404 (S7): the same page as an unknown path.
/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
\ChitChat\View\StatusPage::respond(404, \ChitChat\View\StatusPage::applicationName($config));
