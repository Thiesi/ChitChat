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
    $limits = IconService::limits();
    $background = $_POST['background'] ?? '#14111C';
    $dryRun = $_POST['dry_run'] ?? '0';
    if (!is_string($background) || !in_array($dryRun, ['0', '1'], true)) {
        throw new ApiException(400, 'validation_error', 'Use background and dry_run=0 or 1 form fields.');
    }
    $file = static fn (): ?IncomingFile => isset($_FILES['icon']) ? IncomingFile::fromGlobal('icon', $limits->maxBytes) : null;
    $result = (new IconService($pdo, $config))->update($actor, $file, $background, Request::clientIp(), $dryRun === '1');
    return ApiResult::ok($dryRun === '1' ? $result : ['application_icon' => $result]);
});
