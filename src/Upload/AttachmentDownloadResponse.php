<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use ChitChat\Http\ApiException;

final class AttachmentDownloadResponse
{
    /**
     * Authorization, history/age/deletion checks and storage validation are completed
     * by the caller before this method sees a conditional request.
     *
     * $headersOnly (a HEAD request, #357) sends exactly the same status and headers and never
     * opens the file. Content-Length is the stored size, which the caller has already checked
     * against the file on disk. The one difference: if the file vanishes between that check
     * and the open, GET answers 410 here while HEAD has already said 200.
     *
     * @param array{path:string,name:string,mime_type:string,size_bytes:int,sha256:string,previewable:bool} $attachment
     */
    public static function send(array $attachment, bool $inlineRequested, bool $preview, bool $headersOnly = false): never
    {
        $stream = null;
        if (!$headersOnly) {
            $stream = @fopen($attachment['path'], 'rb');
            if ($stream === false) {
                throw new ApiException(410, 'attachment_storage_missing', 'The attachment file is unavailable.');
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Cache-Control: ' . ($preview ? 'private, no-cache' : 'private, no-store'));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cross-Origin-Resource-Policy: same-origin');
        if ($preview) {
            header('ETag: "' . $attachment['sha256'] . '"');
            if (self::matches($_SERVER['HTTP_IF_NONE_MATCH'] ?? null, $attachment['sha256'])) {
                if ($stream !== null) {
                    fclose($stream);
                }
                http_response_code(304);
                exit;
            }
        }
        $fallback = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachment['name']);
        $fallback = !is_string($fallback) || $fallback === '' ? 'attachment' : substr($fallback, 0, 120);
        $disposition = $preview || ($inlineRequested && $attachment['previewable']) ? 'inline' : 'attachment';
        http_response_code(200);
        header('Content-Type: ' . $attachment['mime_type']);
        header('Content-Length: ' . $attachment['size_bytes']);
        header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($fallback, '"\\') . '"'
            . "; filename*=UTF-8''" . rawurlencode($attachment['name']));
        if ($stream === null) {
            exit;
        }
        fpassthru($stream);
        fclose($stream);
        exit;
    }

    private static function matches(mixed $provided, string $sha): bool
    {
        if (!is_string($provided) || strlen($provided) > 8192) {
            return false;
        }
        foreach (explode(',', $provided) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || $tag === '"' . $sha . '"' || $tag === 'W/"' . $sha . '"') {
                return true;
            }
        }
        return false;
    }
}
