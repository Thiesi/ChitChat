<?php

declare(strict_types=1);

namespace ChitChat\InstallationIcon;

use ChitChat\Config;
use ChitChat\Database;
use Throwable;

/** Public icon requests deliberately never start a PHP session. */
final class IconResponse
{
    public static function respond(Config $config, bool $favicon = false): never
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                header('Allow: GET, HEAD');
                http_response_code(405);
                exit;
            }
            $repository = new IconRepository(Database::connect($config));
            $revision = $favicon ? $repository->currentRevision() : ($_GET['revision'] ?? null);
            $variant = $favicon ? 'ico' : ($_GET['variant'] ?? null);
            if (!is_string($variant) || !isset(IconGeneration::VARIANTS[$variant])
                || (!$favicon && (!is_string($revision) || preg_match('/^[0-9a-f]{64}$/D', $revision) !== 1))) {
                self::missing();
            }
            if ($revision === null) {
                $bytes = file_get_contents(dirname(__DIR__, 2) . '/public/assets/app-icons/favicon.ico');
                if (!is_string($bytes)) {
                    self::missing();
                }
            } else {
                if (!is_string($revision)) {
                    self::missing();
                }
                $row = $repository->find($revision);
                if ($row === null) {
                    self::missing();
                }
                // Read and verify the complete generation before evaluating conditional headers.
                $bytes = $row['generation']->bytes[$variant];
            }
            header('Content-Type: ' . IconGeneration::VARIANTS[$variant]['mime']);
            header('X-Content-Type-Options: nosniff');
            header('Cross-Origin-Resource-Policy: same-origin');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Cache-Control: ' . ($favicon ? 'no-cache' : 'public, max-age=31536000, immutable'));
            $etag = '"' . hash('sha256', $bytes) . '"';
            header('ETag: ' . $etag);
            $provided = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
            if (is_string($provided) && strlen($provided) <= 8192) {
                foreach (explode(',', $provided) as $tag) {
                    if (in_array(trim($tag), ['*', $etag, 'W/' . $etag], true)) {
                        http_response_code(304);
                        exit;
                    }
                }
            }
            header('Content-Length: ' . strlen($bytes));
            if ($method !== 'HEAD') {
                echo $bytes;
            }
            exit;
        } catch (Throwable) {
            header('Cache-Control: no-store');
            http_response_code(503);
            exit;
        }
    }

    private static function missing(): never
    {
        header('Cache-Control: no-store');
        http_response_code(404);
        exit;
    }
}
