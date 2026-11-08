<?php

declare(strict_types=1);

use ChitChat\Auth\SessionManager;
use ChitChat\Auth\UserRepository;
use ChitChat\Database;
use ChitChat\Http\ApiException;
use ChitChat\Http\ApiResult;
use ChitChat\Http\Endpoint;
use ChitChat\Http\Request;
use ChitChat\Policy\PolicyService;

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__, 4) . '/bootstrap/http.php';

Endpoint::run($config, static function () use ($config): ApiResult {
    Request::requireMethod('GET');
    $document = (new PolicyService(Database::connect($config), $config))->publicDocument(Request::queryString('document'));
    if ($document === null) {
        throw new ApiException(404, 'policy_document_not_found', 'That policy document is not published.');
    }
    return ApiResult::ok(['document' => $document]);
});
