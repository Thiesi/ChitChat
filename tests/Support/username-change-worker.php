<?php

declare(strict_types=1);

// One racing participant for UsernameChangeConcurrencyTest; prints a JSON outcome.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$config = ChitChat\Config::fromEnvironment();
$pdo = ChitChat\Database::connect($config);
$name = $pdo->prepare("SELECT set_config('application_name', :name, false)");
$name->execute(['name' => (string) $input['application_name']]);
$users = new ChitChat\Auth\UserRepository($pdo);
$actor = isset($input['actor_id']) ? $users->findAuthenticatedById((int) $input['actor_id']) : null;
try {
    $result = match ((string) $input['action']) {
        'require' => (new ChitChat\Account\UsernameChangeService($pdo))->requireChange(
            $actor ?? throw new RuntimeException('Missing actor.'),
            (int) $input['user_id'], 'Racing reason', isset($input['case_id']) ? (int) $input['case_id'] : null, '127.0.0.50')['user'],
        'choose' => (new ChitChat\Account\UsernameChangeService($pdo))->choose(
            $actor ?? throw new RuntimeException('Missing actor.'), (string) $input['username'], '127.0.0.51')->toArray(),
        'register' => (new ChitChat\Auth\AuthService($pdo, $config))->register(
            (string) $input['username'], 'Racing-Registration-Aa1!', '127.0.0.52')->toArray(),
        'resolve' => ['case' => (new ChitChat\Moderation\ReportService($pdo))->resolve(
            $actor ?? throw new RuntimeException('Missing actor.'), (int) $input['case_id'], 'resolved', 'user_warned', null, '127.0.0.53')['status']],
        default => throw new RuntimeException('Unknown action.'),
    };
    fwrite(STDOUT, json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR));
} catch (ChitChat\Http\ApiException $error) {
    fwrite(STDOUT, json_encode(['status' => $error->status, 'error_code' => $error->errorCode], JSON_THROW_ON_ERROR));
}
