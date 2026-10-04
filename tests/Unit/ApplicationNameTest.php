<?php

declare(strict_types=1);
namespace ChitChat\Tests\Unit;

use ChitChat\Admin\ApplicationNameService;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class ApplicationNameTest extends TestCase
{
    public function testNamesAreTrimmedAndMayUseAnyPrintableCharacters(): void
    {
        self::assertSame('Café Chat', ApplicationNameService::normalize('  Café Chat  '));
        self::assertSame('Team "Hub" & Co.', ApplicationNameService::normalize('Team "Hub" & Co.'));
        $longest = str_repeat('ä', ApplicationNameService::MAXIMUM_LENGTH);
        self::assertSame($longest, ApplicationNameService::normalize($longest));
    }

    public function testEmptyOverlongAndControlCharacterNamesAreRejected(): void
    {
        foreach (['', '   ', str_repeat('a', ApplicationNameService::MAXIMUM_LENGTH + 1), "Chat\nApp", "Chat\u{200E}App", "Chat\u{2028}App"] as $name) {
            try {
                ApplicationNameService::normalize($name);
                self::fail('Expected the name to be rejected: ' . json_encode($name));
            } catch (ApiException $exception) {
                self::assertSame('validation_error', $exception->errorCode);
            }
        }
    }
}
