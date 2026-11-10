<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
putenv('TOTP_ENCRYPTION_KEYS=' . $input['keys']);
putenv('TOTP_ACTIVE_KEY_ID=' . $input['active_key']);
$config = ChitChat\Config::fromEnvironment();
$pdo = ChitChat\Database::connect($config);
$name = $pdo->prepare("SELECT set_config('application_name', :name, false)");
$name->execute(['name' => $input['application_name']]);
$user = (new ChitChat\Auth\UserRepository($pdo))->findAuthenticatedById((int) $input['user_id']);
if ($user === null) throw new RuntimeException('Missing test user.');
try {
    $result = (new ChitChat\Auth\Totp\TotpService($pdo, $config))->complete(
        $user, (string) $input['code'], (string) $input['purpose'], (string) $input['ip'],
        static function (callable $verify) use ($pdo, $user): string {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR NO KEY UPDATE');
            $lock->execute(['id' => $user->id]);
            $verify();
            return 'accepted';
        },
    );
    fwrite(STDOUT, json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR));
} catch (ChitChat\Http\ApiException $error) {
    fwrite(STDOUT, json_encode(['status' => $error->status, 'error_code' => $error->errorCode], JSON_THROW_ON_ERROR));
}