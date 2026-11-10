<?php

declare(strict_types=1);

use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\InstallationIcon\IconService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$fixture = json_decode((string) file_get_contents($argv[1]), true, 8, JSON_THROW_ON_ERROR);
$config = Config::fromEnvironment();
$pdo = Database::connect($config);
file_put_contents($fixture['pid_file'], (string) $pdo->query('SELECT pg_backend_pid()')->fetchColumn());
try {
    $actor = (new UserRepository($pdo))->findAuthenticatedById((int) $fixture['user_id']);
    if ($actor === null) throw new RuntimeException('Missing test actor.');
    $saved = (new IconService($pdo, $config))->choosePreset($actor, 'initial', '127.0.0.1');
    $result = ['revision' => $saved['revision']];
} catch (ApiException $error) {
    $result = ['error' => $error->errorCode, 'status' => $error->status];
}
file_put_contents($fixture['result_file'], json_encode($result, JSON_THROW_ON_ERROR));
