<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\Totp\TotpProtocol;
use ChitChat\Http\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpProtocolTest extends TestCase
{
    /** SHA-1 six-digit suffixes of RFC 6238 Appendix B's eight-digit vectors.
     * @return iterable<string,array{int,string}>
     */
    public static function vectors(): iterable
    {
        yield 'early epoch' => [59, '287082'];
        yield 'leading zero' => [1111111109, '081804'];
        yield 'next step' => [1111111111, '050471'];
        yield 'two leading zeroes' => [1234567890, '005924'];
        yield '2033' => [2000000000, '279037'];
        yield 'beyond 32-bit Unix time' => [20000000000, '353130'];
    }

    #[DataProvider('vectors')]
    public function testRfcVectors(int $time, string $code): void
    {
        self::assertSame($code, TotpProtocol::codeAtStep('12345678901234567890', intdiv($time, 30)));
    }

    public function testWindowBoundariesReplayAndBackwardClockNeverResetTheFence(): void
    {
        $seed = '12345678901234567890';
        $current = 100;
        foreach ([99, 100, 101] as $step) {
            $code = TotpProtocol::codeAtStep($seed, $step);
            self::assertSame($step, TotpProtocol::matchingStep($seed, $code, 3000, -1));
            self::assertNull(TotpProtocol::matchingStep($seed, $code, 3000, $step));
        }
        foreach ([98, 102] as $step) {
            self::assertNull(TotpProtocol::matchingStep($seed, TotpProtocol::codeAtStep($seed, $step), 3000, -1));
        }
        self::assertSame(100, TotpProtocol::matchingStep($seed, TotpProtocol::codeAtStep($seed, 100), 2999, -1));
        self::assertNull(TotpProtocol::matchingStep($seed, TotpProtocol::codeAtStep($seed, 99), 2970, $current));
        self::assertSame(0, TotpProtocol::matchingStep($seed, TotpProtocol::codeAtStep($seed, 0), 0, -1));
    }

    public function testSeparatorsAreNormalizedAndLeadingZeroesRemainStrings(): void
    {
        self::assertSame('005924', TotpProtocol::normalizeCode(' 00-59.24 '));
        self::assertSame(41152263, TotpProtocol::matchingStep('12345678901234567890', '00 59-24', 1234567890, -1));
        foreach (['12345', '1234567', '１２３４５６', '١٢٣٤٥٦', "123\t456", "123\n456", '123e45', "123456\0", str_repeat(' ', 65) . '123456'] as $invalid) {
            try {
                TotpProtocol::normalizeCode($invalid);
                self::fail('Malformed code was accepted.');
            } catch (ApiException $error) {
                self::assertSame(400, $error->status);
                self::assertSame('validation_error', $error->errorCode);
                self::assertStringNotContainsString($invalid, $error->getMessage());
            }
        }
    }

    public function testBase32AndProvisioningRemainLocalAndUnambiguous(): void
    {
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', TotpProtocol::base32('12345678901234567890'));
        $uri = TotpProtocol::provisioningUri('12345678901234567890', 'Family: Chat & friends', 'Ada: Example');
        self::assertStringStartsWith('otpauth://totp/Family%20%20Chat%20%26%20friends:Ada%20%20Example?', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        self::assertSame([
            'secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            'issuer' => 'Family  Chat & friends', 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30',
        ], $query);
    }
}