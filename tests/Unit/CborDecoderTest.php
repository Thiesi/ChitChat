<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Auth\CborDecoder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CborDecoderTest extends TestCase
{
    public function testDecodesModeratelyNestedArrays(): void
    {
        $depth = 16;
        $data = str_repeat("\x81", $depth) . "\x00";

        $value = CborDecoder::decode($data);

        for ($index = 0; $index < $depth; $index++) {
            self::assertIsArray($value);
            self::assertCount(1, $value);
            $value = $value[0];
        }
        self::assertSame(0, $value);
    }

    public function testRejectsExcessivelyNestedArraysInsteadOfExhaustingTheStack(): void
    {
        $data = str_repeat("\x81", 10_000) . "\x00";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('depth');
        CborDecoder::decode($data);
    }

    public function testRejectsExcessivelyNestedMapsInsteadOfExhaustingTheStack(): void
    {
        // Each level: a 1-pair map (0xa1) whose key is integer 0 (0x00) and whose value nests further.
        $data = str_repeat("\xa1\x00", 10_000) . "\x00";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('depth');
        CborDecoder::decode($data);
    }

    public function testRejectsExcessivelyNestedTagsInsteadOfExhaustingTheStack(): void
    {
        // Major type 6 (tag) with a 1-byte tag value (0xc0), nested repeatedly around a final integer.
        $data = str_repeat("\xc0", 10_000) . "\x00";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('depth');
        CborDecoder::decode($data);
    }
}
