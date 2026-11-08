<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\AuthenticatedUser;
use ChitChat\Config;
use ChitChat\Http\ApiException;
use ChitChat\Upload\AttachmentPolicy;
use ChitChat\Upload\AttachmentUploadLimits;
use ChitChat\Upload\IncomingFile;
use PHPUnit\Framework\TestCase;

final class AttachmentUploadLimitsTest extends TestCase
{
    public function testEffectiveFileLimitUsesPositivePhpBoundsAndIniUnits(): void
    {
        foreach ([
            [10_485_760, '2M', 2_097_152],
            [1024, '2M', 1024],
            [10_485_760, '512k', 524_288],
            [10_485_760, '1G', 10_485_760],
            [10_485_760, '0', 10_485_760],
            [10_485_760, '-1', 10_485_760],
        ] as [$applicationMaxBytes, $phpLimit, $expected]) {
            self::assertSame($expected, AttachmentUploadLimits::fromIni($applicationMaxBytes, $phpLimit, '0')->maxBytes);
        }
    }

    public function testWholeBodyHeadroomIsIncludedWithoutOverstatingTinyLimits(): void
    {
        foreach ([
            [10_485_760, '10M', '8M', 8_323_072],
            [10_485_760, '2M', '8M', 2_097_152],
            [1024, '2M', '8M', 1024],
            [10_485_760, '512', '8M', 512],
            [10_485_760, '10M', '1K', 0],
            [10_485_760, '10M', '64K', 0],
            [10_485_760, '10M', '65K', 1024],
            [10_485_760, '10M', '66048', 512],
        ] as [$applicationMaxBytes, $uploadLimit, $postLimit, $expected]) {
            self::assertSame($expected, AttachmentUploadLimits::fromIni($applicationMaxBytes, $uploadLimit, $postLimit)->maxBytes);
        }
    }

    public function testOnlyEvidenceOfAnExceededPostLimitProduces413(): void
    {
        $limits = AttachmentUploadLimits::fromIni(4096, '2K', '1K');
        foreach ([null, '', 'invalid', '0', '1023', '1024', 1024] as $length) {
            $limits->requireBodyFits($length);
        }
        foreach (['0', '-1'] as $unlimited) {
            AttachmentUploadLimits::fromIni(4096, '2K', $unlimited)->requireBodyFits('99999999');
        }
        try {
            $limits->requireBodyFits('1025');
            self::fail('Expected a discarded-body rejection.');
        } catch (ApiException $exception) {
            self::assertSame(413, $exception->status);
            self::assertSame('attachment_too_large', $exception->errorCode);
            self::assertSame(0, $exception->maxBytes);
        }
    }

    public function testPhpFileSizeErrorsIncludeTheEffectiveLimitButMissingFilesStay400(): void
    {
        $originalFiles = $_FILES;
        try {
            foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
                $_FILES = ['file' => ['name' => 'large.txt', 'tmp_name' => '', 'size' => 0, 'error' => $error]];
                try {
                    IncomingFile::fromGlobal('file', 2048);
                    self::fail('Expected a PHP upload-size rejection.');
                } catch (ApiException $exception) {
                    self::assertSame(413, $exception->status);
                    self::assertSame('attachment_too_large', $exception->errorCode);
                    self::assertSame(2048, $exception->maxBytes);
                }
            }
            $_FILES = [];
            try {
                IncomingFile::fromGlobal('file', 2048);
                self::fail('Expected a missing-file rejection.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->status);
                self::assertSame('attachment_missing', $exception->errorCode);
                self::assertNull($exception->maxBytes);
            }
        } finally {
            $_FILES = $originalFiles;
        }
    }

    public function testDiscoveryIsUnavailableToAnonymousAndGuestSessions(): void
    {
        $config = Config::fromEnvironment();
        self::assertNull(AttachmentPolicy::forSession($config, null));
        self::assertNull(AttachmentPolicy::forSession($config, new AuthenticatedUser(1, 'Visitor', [], 1, guest: true)));
    }

    public function testDiscoveryKeepsIndependentRoomAndDirectUploadPoliciesAndEnforcedMimeLists(): void
    {
        $variables = [
            'RATE_LIMIT_ATTACHMENT_UPLOAD_MAX_ATTEMPTS' => '4',
            'RATE_LIMIT_ATTACHMENT_UPLOAD_WINDOW_SECONDS' => '120',
            'RATE_LIMIT_DIRECT_MESSAGE_ATTACHMENT_UPLOAD_MAX_ATTEMPTS' => '6',
            'RATE_LIMIT_DIRECT_MESSAGE_ATTACHMENT_UPLOAD_WINDOW_SECONDS' => '600',
        ];
        $saved = [];
        foreach ($variables as $key => $value) {
            $saved[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        try {
            $config = Config::fromEnvironment();
            $policy = AttachmentPolicy::forSession($config, new AuthenticatedUser(1, 'Alice', [], 1));
            self::assertNotNull($policy);
            self::assertSame(AttachmentUploadLimits::fromConfig($config)->maxBytes, $policy['max_bytes']);
            self::assertSame([
                'room' => ['maximum_attempts' => 4, 'window_seconds' => 120],
                'direct_message' => ['maximum_attempts' => 6, 'window_seconds' => 600],
            ], $policy['rate_limits']);
            foreach ($policy['allowed_mime_types'] as $mimeType) {
                self::assertTrue(AttachmentPolicy::isAllowed($mimeType));
            }
            foreach ($policy['previewable_mime_types'] as $mimeType) {
                self::assertTrue(AttachmentPolicy::isAllowed($mimeType));
                self::assertTrue(AttachmentPolicy::isPreviewable($mimeType));
            }
            self::assertNotContains('image/svg+xml', $policy['allowed_mime_types']);
        } finally {
            foreach ($saved as $key => $value) {
                putenv($value === false ? $key : $key . '=' . $value);
            }
        }
    }
}
