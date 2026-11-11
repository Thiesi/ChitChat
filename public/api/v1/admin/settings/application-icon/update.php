<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\InstallationIcon\IconService;
use ChitChat\Upload\IncomingFile;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 6) . '/bootstrap/http.php';
Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('POST');
    SessionManager::requireCsrf(Request::csrfHeader());
    $pdo = Database::connect($config);
    $actor = SessionManager::requireUser(new UserRepository($pdo));
    IconService::requireAdministrator($actor);
    SessionManager::requirePrivilegedStepUp($actor, $config);
    header('Cache-Control: no-store');
    $service = new IconService($pdo, $config);
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType === 'application/json') {
        $payload = Request::json();
        if (array_key_exists('background', $payload)) {
            throw new ApiException(422, 'preset_background_fixed', 'This preset has its own background. Choose an upload to change the background.');
        }
        if (array_diff(array_keys($payload), ['preset', 'dry_run']) !== []) {
            throw new ApiException(400, 'validation_error', 'Choose a preset or an uploaded image, not both.');
        }
        $preset = Request::string($payload, 'preset');
        $dryRun = $payload['dry_run'] ?? false;
        if (!is_bool($dryRun)) throw new ApiException(400, 'validation_error', 'dry_run must be a boolean.');
        $result = $service->choosePreset($actor, $preset, Request::clientIp(), $dryRun);
        return ApiResult::ok($dryRun ? $result : ['application_icon' => $result]);
    }
    if (array_key_exists('preset', $_POST) || array_key_exists('preset_id', $_POST)) {
        throw new ApiException(400, 'validation_error', 'Send preset selection as JSON without an uploaded image.');
    }
    $limits = IconService::limits();
    $background = $_POST['background'] ?? '#14111C';
    $dryRun = $_POST['dry_run'] ?? '0';
    if (!is_string($background) || !in_array($dryRun, ['0', '1'], true)) {
        throw new ApiException(400, 'validation_error', 'Use background and dry_run=0 or 1 form fields.');
    }
    $file = static fn (): ?IncomingFile => isset($_FILES['icon']) ? IncomingFile::fromGlobal('icon', $limits->maxBytes) : null;
    $result = $service->update($actor, $file, $background, Request::clientIp(), $dryRun === '1');
    return ApiResult::ok($dryRun === '1' ? $result : ['application_icon' => $result]);
});
