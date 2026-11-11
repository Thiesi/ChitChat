<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use ChitChat\Account\UsernameChangeService;
use ChitChat\Auth\AuthService;
use ChitChat\Auth\UserRepository;
use ChitChat\Config;
use ChitChat\Database;

// A pending required username change (#164) must survive backup/restore: the
// flag, the reason, the removed name's reservation and the sign-in fallback.
$config = Config::fromEnvironment();
if ($config->environment !== 'test' || !in_array($config->databaseName, ['chitchat_backup', 'chitchat_backup_restore'], true)) {
    throw new RuntimeException('Username-change backup fixture requires the rehearsal database.');
}
$pdo = Database::connect($config);
$expectedPath = $argv[2] ?? throw new RuntimeException('Expected metadata path is required.');
$password = 'Username-Backup-Fixture-Aa1!';
if (($argv[1] ?? '') === 'seed') {
    $auth = new AuthService($pdo, $config);
    $moderator = $auth->register('BackupModerator', $password, '127.0.0.1');
    $pdo->exec("INSERT INTO user_roles (user_id, role) VALUES ({$moderator->id}, 'global_moderator')");
    $moderator = (new UserRepository($pdo))->findAuthenticatedById($moderator->id) ?? throw new RuntimeException('Moderator vanished.');
    $member = $auth->register('Backup.Rude', $password, '127.0.0.2');
    $pdo->exec('UPDATE system_settings SET username_reservation_days = 30 WHERE id = 1');
    $placeholder = (new UsernameChangeService($pdo))->requireChange($moderator, $member->id, 'Backup fixture reason', null, '127.0.0.1')['user']['username'];
    file_put_contents($expectedPath, json_encode(['member_id' => $member->id, 'placeholder' => $placeholder], JSON_THROW_ON_ERROR));
} elseif (($argv[1] ?? '') === 'verify') {
    $expected = json_decode((string) file_get_contents($expectedPath), true, 8, JSON_THROW_ON_ERROR);
    $memberId = (int) $expected['member_id'];
    $row = $pdo->query("SELECT username, username_change_reason, username_change_previous_canonical, (username_change_required_at IS NOT NULL)::int AS pending FROM users WHERE id = {$memberId}")->fetch();
    if (!is_array($row) || $row['username'] !== $expected['placeholder'] || $row['username_change_reason'] !== 'Backup fixture reason'
        || $row['username_change_previous_canonical'] !== 'backup.rude' || (int) $row['pending'] !== 1) {
        throw new RuntimeException('Restored pending username change differs.');
    }
    if ((int) $pdo->query("SELECT COUNT(*) FROM username_reservations WHERE username_canonical = 'backup.rude' AND user_id = {$memberId} AND reserved_until IS NULL")->fetchColumn() !== 1
        || (int) $pdo->query('SELECT username_reservation_days FROM system_settings WHERE id = 1')->fetchColumn() !== 30) {
        throw new RuntimeException('Restored username reservation or its period differs.');
    }
    if ((new AuthService($pdo, $config))->authenticatePassword('Backup.Rude', $password, '127.0.0.3')->id !== $memberId) {
        throw new RuntimeException('Restored account no longer signs in with its removed name.');
    }
    echo "Pending username change, its reservation and the removed-name sign-in survived backup/restore.\n";
} else {
    throw new RuntimeException('Use seed or verify.');
}
