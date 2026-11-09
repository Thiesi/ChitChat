<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$fixture = json_decode((string) file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$base = \ChitChat\Config::fromEnvironment();
$args = [];
foreach ((new ReflectionClass(\ChitChat\Config::class))->getConstructor()->getParameters() as $parameter) {
    $name = $parameter->getName();
    $args[$name] = $fixture['config'][$name] ?? $base->{$name};
}
$config = new \ChitChat\Config(...$args);
$pdo = \ChitChat\Database::connect($config);
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path($fixture['sessions']);
session_start();
$_SESSION = $fixture['session'];
file_put_contents($fixture['pid_file'], $pdo->query('SELECT pg_backend_pid()')->fetchColumn() . "\n");
try {
    $result = (new \ChitChat\Auth\NativeAuth\NativeAuthExchange($pdo, $config))->exchange($fixture['grant'], '198.51.100.2');
    echo json_encode(['outcome' => $result['outcome'], 'user_id' => $result['user']['id']], JSON_THROW_ON_ERROR);
} catch (\ChitChat\Http\ApiException $exception) {
    echo json_encode(['error_code' => $exception->errorCode], JSON_THROW_ON_ERROR);
} catch (PDOException $exception) {
    echo json_encode(['sqlstate' => $exception->getCode()], JSON_THROW_ON_ERROR);
}
session_write_close();