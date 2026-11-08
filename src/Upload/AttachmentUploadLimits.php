<?php

declare(strict_types=1);

namespace ChitChat\Upload;

use ChitChat\Config;
use ChitChat\Http\ApiException;

final readonly class AttachmentUploadLimits
{
    private const MULTIPART_HEADROOM_BYTES = 65_536;

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
        $postMaxBytes = ini_parse_quantity($postMaxSize);
        // PHP enforces these bounds only when positive; 0/-1 are unlimited.
        $maxBytes = $uploadMaxBytes > 0 ? min($applicationMaxBytes, $uploadMaxBytes) : $applicationMaxBytes;
        if ($postMaxBytes > 0) {
            // Reserve bounded multipart/caption overhead. Never advertise a
            // positive file allowance when this installation cannot fit it.
            $maxBytes = min($maxBytes, max(0, $postMaxBytes - self::MULTIPART_HEADROOM_BYTES));
        }
        return new self($maxBytes, $postMaxBytes);
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
