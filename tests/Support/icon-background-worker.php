<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
$config = \ChitChat\Config::fromEnvironment();
if ($config->environment !== 'test' || $config->databaseName !== 'chitchat_test') {
    throw new RuntimeException('Icon concurrency fixture requires the owned test database.');
}
$fixture = json_decode((string)file_get_contents($argv[1]),true,16,JSON_THROW_ON_ERROR);
$pdo = \ChitChat\Database::connect($config);
$pdo->exec("SET lock_timeout='10s'");
$pdo->exec("SET statement_timeout='15s'");
file_put_contents($fixture['pid_file'],(string)$pdo->query('SELECT pg_backend_pid()')->fetchColumn());
$actor = (new \ChitChat\Auth\UserRepository($pdo))->findAuthenticatedById($fixture['user_id']);
if ($actor === null) throw new RuntimeException('Fixture actor missing.');
try {
    (new \ChitChat\InstallationIcon\IconService($pdo,$config))->update($actor,null,'#FBF7F1','127.0.0.1');
    $result = ['status'=>'saved'];
} catch (\ChitChat\Http\ApiException $e) {
    $result = ['error_code'=>$e->errorCode,'status'=>$e->status];
}
file_put_contents($fixture['result_file'],json_encode($result,JSON_THROW_ON_ERROR));
