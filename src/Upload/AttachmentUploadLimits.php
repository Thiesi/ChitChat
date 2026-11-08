<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use ChitChat\Config;
use ChitChat\Http\ApiException;

final readonly class AttachmentUploadLimits
{
    private function __construct(public int $maxBytes, private int $postMaxBytes)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return self::fromIni(
            $config->attachmentMaxBytes,
            ini_get('upload_max_filesize') ?: '0',
            ini_get('post_max_size') ?: '0',
        );
    }

    public static function fromIni(int $applicationMaxBytes, string $uploadMaxSize, string $postMaxSize): self
    {
        $uploadMaxBytes = ini_parse_quantity($uploadMaxSize);
        // PHP enforces these bounds only when positive; 0/-1 are unlimited.
        return new self(
            $uploadMaxBytes > 0 ? min($applicationMaxBytes, $uploadMaxBytes) : $applicationMaxBytes,
            ini_parse_quantity($postMaxSize),
        );
    }

    public function requireBodyFits(mixed $contentLength): void
    {
        $length = filter_var($contentLength, FILTER_VALIDATE_INT);
        if ($this->postMaxBytes > 0 && $length !== false && $length > $this->postMaxBytes) {
            throw new ApiException(
                413,
                'attachment_too_large',
                'The uploaded body exceeds the server upload limit.',
                maxBytes: $this->maxBytes,
            );
        }
    }
}
