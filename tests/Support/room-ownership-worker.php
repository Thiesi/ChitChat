<?php

declare(strict_types=1);

// One racing participant for RoomOwnershipConcurrencyTest; prints a JSON outcome.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$input = json_decode((string) stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
$config = ChitChat\Config::fromEnvironment();
$pdo = ChitChat\Database::connect($config);
$name = $pdo->prepare("SELECT set_config('application_name', :name, false)");
$name->execute(['name' => (string) $input['application_name']]);
$actor = (new ChitChat\Auth\UserRepository($pdo))->findAuthenticatedById((int) $input['actor_id'])
    ?? throw new RuntimeException('Missing actor.');
$rooms = new ChitChat\Room\RoomService($pdo);
$roomId = (int) $input['room_id'];
try {
    $result = match ((string) $input['action']) {
        'transfer' => ['owner' => $rooms->transferOwnership($actor, $roomId, (int) $input['target_user_id'], '127.0.0.60')->memberRole],
        'leave' => (static function () use ($rooms, $actor, $roomId): array {
            $rooms->leave($actor, $roomId, '127.0.0.61');
            return ['left' => true];
        })(),
        'set_role' => (static function () use ($rooms, $actor, $roomId, $input): array {
            $rooms->setRole($actor, $roomId, (int) $input['target_user_id'], (string) $input['role'], '127.0.0.62');
            return ['role' => (string) $input['role']];
        })(),
        'remove_member' => (static function () use ($pdo, $actor, $roomId, $input): array {
            (new ChitChat\Admin\RoomAdminService($pdo))->removeMember($actor, $roomId, (int) $input['target_user_id'], '127.0.0.63');
            return ['removed' => true];
        })(),
        default => throw new RuntimeException('Unknown action.'),
    };
    fwrite(STDOUT, json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR));
} catch (ChitChat\Http\ApiException $error) {
    fwrite(STDOUT, json_encode(['status' => $error->status, 'error_code' => $error->errorCode], JSON_THROW_ON_ERROR));
}
