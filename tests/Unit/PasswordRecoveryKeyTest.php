<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\PasswordRecoveryKey;
use PHPUnit\Framework\TestCase;

final class PasswordRecoveryKeyTest extends TestCase
{
    public function testGeneratedKeysHaveTheGroupedCrockfordFormatAndRoundTrip(): void
    {
        $keys = [];
        for ($index = 0; $index < 16; $index++) {
            $key = PasswordRecoveryKey::generate();
            self::assertMatchesRegularExpression('/\A[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){5}\z/', $key);
            self::assertSame(str_replace('-', '', $key), PasswordRecoveryKey::normalize($key));
            self::assertSame(64, strlen((string) PasswordRecoveryKey::hash($key)));
            $keys[] = $key;
        }
        self::assertCount(16, array_unique($keys));
    }

    public function testInputAcceptsOnlyDocumentedSeparatorsCaseAndConfusables(): void
    {
        $canonical = '011101110111011101110111';
        self::assertSame($canonical, PasswordRecoveryKey::normalize("oIl1-oIl1 oil1\toil1\noil1-oil1"));
        self::assertSame(PasswordRecoveryKey::hash($canonical), PasswordRecoveryKey::hash("oIl1-oIl1 oil1\toil1\noil1-oil1"));
        foreach (['short', str_repeat('U', 24), str_repeat('A', 23) . '!', str_repeat('A', 257), "bad\xff"] as $invalid) {
            self::assertNull(PasswordRecoveryKey::normalize($invalid));
        }
    }

    public function testPasswordPolicyFailureDoesNotExposeTheSubmittedPasswordInATrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        self::assertNotFalse($previous);
        $secret = 's3cr3t!';
        try {
            \ChitChat\Auth\PasswordPolicy::validate($secret, 'Member');
            self::fail('Expected a weak-password failure.');
        } catch (\ChitChat\Http\ApiException $exception) {
            self::assertStringContainsString('PasswordPolicy::validate', (string) $exception);
            self::assertStringNotContainsString($secret, (string) $exception);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
}
