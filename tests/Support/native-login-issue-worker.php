<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ChitChat\Auth\LoginSessionRepository;
use ChitChat\Auth\NativeAuth\NativeAuthGuard;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Database;

$fixture = json_decode((string) file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$config = Config::fromEnvironment();
$pdo = Database::connect($config);
file_put_contents($fixture['pid_file'], (string) $pdo->query('SELECT pg_backend_pid()')->fetchColumn());
$pdo->beginTransaction();
try {
    $target = (new UserRepository($pdo))->findAuthenticatedById($fixture['target']);
    if ($target === null) throw new RuntimeException('Missing target account.');
    (new NativeAuthGuard($pdo, $config))->lockAccountPolicy($target->id, false, $fixture['replaced']['user_id']);
    $issued = (new LoginSessionRepository($pdo))->issue($target, null, $fixture['replaced']);
    $pdo->commit();
    echo json_encode(['id' => $issued->id], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $exception->__toString());
    exit(1);
}
