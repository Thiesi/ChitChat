<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Admin\AccessibilityFeedbackService;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class AccessibilityFeedbackTest extends TestCase
{
    public function testStrictHttpsAndMailtoAddressesAreAccepted(): void
    {
        self::assertNull(AccessibilityFeedbackService::validateUrl(null));
        foreach ([
            'https://example.org',
            'https://help.example.org/accessibility?from=chitchat#form',
            'https://example.org:8443/a11y',
            'mailto:access@example.org',
            'mailto:access@example.org?subject=Accessibility%20problem',
            'https://example.org/' . str_repeat('a', AccessibilityFeedbackService::MAX_LENGTH - 20),
        ] as $valid) {
            self::assertSame($valid, AccessibilityFeedbackService::validateUrl($valid));
        }
    }

    public function testEverythingElseIsRefused(): void
    {
        foreach ([
            // Other schemes, and a bare host (#219 review).
            'javascript:alert(1)',
            'data:text/html,<p>hi</p>',
            'http://example.org',
            'example.org',
            '//example.org',
            'ftp://example.org',
            'file:///etc/passwd',
            // Only the lower-case schemes.
            'HTTPS://example.org',
            'Mailto:access@example.org',
            // https needs a host and no user name.
            'https://',
            'https:///path',
            'https://user@example.org',
            'https://user:secret@example.org',
            // mailto needs exactly one valid address.
            'mailto:',
            'mailto:not-an-address',
            'mailto:a@example.org,b@example.org',
            'mailto:?subject=hi',
            // No spaces, control or non-ASCII characters, no leading or trailing blanks.
            ' https://example.org',
            "https://example.org\n",
            'https://example.org/a b',
            "https://example.org/\t",
            "https://example.org/\x00",
            "https://example.org/\x7F",
            'https://exämple.org',
            // Length cap.
            'https://example.org/' . str_repeat('a', AccessibilityFeedbackService::MAX_LENGTH),
            // Not a string.
            '', 'off', 1, true, ['https://example.org'],
        ] as $invalid) {
            try {
                AccessibilityFeedbackService::validateUrl($invalid);
                self::fail('Accepted ' . var_export($invalid, true));
            } catch (ApiException $exception) {
                self::assertSame([400, 'validation_error'], [$exception->status, $exception->errorCode]);
            }
        }
    }

    public function testTheResolvedLinkFallsBackToTheProjectFormAndIsNullWhenOff(): void
    {
        self::assertSame(
            'https://github.com/Thiesi/ChitChat/issues/new?template=accessibility.yml',
            AccessibilityFeedbackService::DEFAULT_URL,
        );
        self::assertSame(['url' => AccessibilityFeedbackService::DEFAULT_URL], AccessibilityFeedbackService::toPublic(true, null));
        self::assertSame(['url' => 'mailto:a@example.org'], AccessibilityFeedbackService::toPublic(true, 'mailto:a@example.org'));
        self::assertNull(AccessibilityFeedbackService::toPublic(false, null));
        self::assertNull(AccessibilityFeedbackService::toPublic(false, 'https://example.org'));
    }
}
