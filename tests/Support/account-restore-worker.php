<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$fixture = json_decode((string) file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$config = \ChitChat\Config::fromEnvironment();
if ($config->environment !== 'test' || !in_array($config->databaseName, ['chitchat_test', 'chitchat_browser', 'chitchat_webkit'], true)) {
    throw new RuntimeException('Restore worker requires an explicit test database.');
}
$pdo = \ChitChat\Database::connect($config);
$pdo->exec("SET lock_timeout = '10s'");
$pdo->exec("SET statement_timeout = '15s'");
file_put_contents($fixture['pid_file'], (string) $pdo->query('SELECT pg_backend_pid()')->fetchColumn());
try {
    $user = (new \ChitChat\Account\AccountClosureService($pdo, $config))->completeRestore($fixture['user_id'], '192.0.2.2');
    $result = ['user_id' => $user->id, 'session_version' => $user->sessionVersion];
} catch (\ChitChat\Http\ApiException $exception) {
    $result = ['error_code' => $exception->errorCode, 'status' => $exception->status, 'in_transaction' => $pdo->inTransaction()];
} catch (PDOException $exception) {
    $result = ['sqlstate' => $exception->getCode()];
}
file_put_contents($fixture['result_file'], json_encode($result, JSON_THROW_ON_ERROR));
