<?php

declare(strict_types=1);

namespace ChitChat\Tests\Unit;

use ChitChat\Search\SearchExcerpt;
use ChitChat\Search\TsqueryLexemes;
use PHPUnit\Framework\TestCase;

/** The match-centred search excerpt and its offsets (#283). */
final class SearchExcerptTest extends TestCase
{
    private const S = SearchExcerpt::START;
    private const E = SearchExcerpt::STOP;

    public function testAShortBodyIsReturnedWholeWithItsMatches(): void
    {
        $result = SearchExcerpt::build("  The  release\tnotes are out.  ", [[7, 7]]);
        self::assertSame('The release notes are out.', $result['excerpt'], 'Whitespace normalised as before.');
        self::assertSame([['start' => 4, 'length' => 7]], $result['matches']);
        self::assertSame('release', $this->slice($result, 0));
    }

    public function testAMatchFarIntoALongBodyIsCentredWithEllipsesOnBothSides(): void
    {
        $body = str_repeat('alpha beta gamma delta ', 30) . 'needle ' . str_repeat('omega psi chi phi ', 30);
        $at = mb_strpos($body, 'needle');
        $result = SearchExcerpt::build($body, [[$at, 6]]);

        self::assertLessThanOrEqual(SearchExcerpt::MAX, mb_strlen($result['excerpt']));
        self::assertStringStartsWith('… ', $result['excerpt']);
        self::assertStringEndsWith(' …', $result['excerpt']);
        self::assertSame('needle', $this->slice($result, 0));
        // About 100 characters of context before it, starting at a word.
        self::assertGreaterThan(80, $result['matches'][0]['start']);
        self::assertLessThanOrEqual(102, $result['matches'][0]['start']);
        self::assertMatchesRegularExpression('/^… (alpha|beta|gamma|delta) /', $result['excerpt']);
        self::assertMatchesRegularExpression('/ (omega|psi|chi|phi) …$/', $result['excerpt'], 'Cut at a word boundary.');
    }

    public function testAMatchNearTheStartHasOnlyATrailingEllipsis(): void
    {
        $body = 'Needle first, then ' . str_repeat('filler words here ', 40);
        $result = SearchExcerpt::build($body, [[0, 6]]);
        self::assertStringStartsWith('Needle first', $result['excerpt']);
        self::assertStringEndsWith(' …', $result['excerpt']);
        self::assertSame([['start' => 0, 'length' => 6]], $result['matches']);
    }

    public function testAMatchNearTheEndUsesTheWholeBudgetEndingAtTheEnd(): void
    {
        $body = str_repeat('filler words here ', 40) . 'the needle';
        $result = SearchExcerpt::build($body, [[mb_strpos($body, 'needle'), 6]]);
        self::assertStringStartsWith('… ', $result['excerpt']);
        self::assertStringEndsWith('the needle', $result['excerpt']);
        self::assertGreaterThan(SearchExcerpt::MAX - 20, mb_strlen($result['excerpt']));
        self::assertSame('needle', $this->slice($result, 0));
    }

    public function testEveryMatchInsideTheWindowIsListedAndOnesOutsideAreNot(): void
    {
        $body = 'needle at the start ' . str_repeat('filler words here ', 40) . 'needle later';
        $result = SearchExcerpt::build($body, [[0, 6], [mb_strrpos($body, 'needle'), 6]]);
        self::assertCount(1, $result['matches'], 'The second match lies beyond the window.');

        $short = SearchExcerpt::build('needle and needle', [[0, 6], [11, 6]]);
        self::assertSame([['start' => 0, 'length' => 6], ['start' => 11, 'length' => 6]], $short['matches']);
    }

    public function testOffsetsAreInUtf16CodeUnits(): void
    {
        // 🎉 is one character but two UTF-16 code units; é written as e + a combining accent is two.
        $body = "Party 🎉 cafe\u{0301} needle";
        $result = SearchExcerpt::build($body, [[mb_strpos($body, 'needle'), 6]]);
        self::assertSame([['start' => 15, 'length' => 6]], $result['matches']);
        $utf16 = mb_convert_encoding($result['excerpt'], 'UTF-16LE', 'UTF-8');
        self::assertSame('needle', mb_convert_encoding(substr($utf16, 15 * 2, 6 * 2), 'UTF-8', 'UTF-16LE'));
    }

    public function testWithoutAMatchALongBodyStartsAtTheBeginningAsBefore(): void
    {
        $body = str_repeat('word ', 100);
        $result = SearchExcerpt::build($body, []);
        self::assertSame(SearchExcerpt::MAX, mb_strlen($result['excerpt']));
        self::assertStringEndsWith('…', $result['excerpt']);
        self::assertSame([], $result['matches']);
    }

    public function testMarksAreReadFromTheHeadlineAndOnlyTrustedWhenTheTextIsUnchanged(): void
    {
        self::assertSame([[4, 7], [22, 7]], SearchExcerpt::marks('The ' . self::S . 'release' . self::E . ' went out, ' . self::S . 'release' . self::E, 'The release went out, release'));
        self::assertNull(SearchExcerpt::marks('The ' . self::S . 'release' . self::E, 'Something else'));
    }

    public function testSentinelsAlreadyInABodyCantForgeAMark(): void
    {
        $body = 'fake ' . self::S . 'mark' . self::E . ' and needle';
        $clean = SearchExcerpt::clean($body);
        self::assertSame('fake mark and needle', $clean);
        // PostgreSQL marks the cleaned text; only its own marks count.
        $marks = SearchExcerpt::marks('fake mark and ' . self::S . 'needle' . self::E, $clean);
        self::assertSame([[14, 6]], $marks);
        self::assertSame([['start' => 14, 'length' => 6]], SearchExcerpt::build($clean, $marks)['matches']);
    }

    public function testOnlyMarksOfIncludedLexemesAreKept(): void
    {
        $body = 'apple banana cherry';
        $marks = [[0, 5], [6, 6]];
        // "apple OR -banana": ts_headline marks banana too.
        self::assertSame([[0, 5]], SearchExcerpt::included($body, $marks, TsqueryLexemes::included("'apple' | !'banana'")));
        self::assertSame($marks, SearchExcerpt::included($body, $marks, null));
        self::assertSame([[0, 5]], SearchExcerpt::included('APPLE x', [[0, 5]], ['apple']), 'Lexemes are lower case.');
    }

    public function testIncludedLexemesFromTheCanonicalTsqueryText(): void
    {
        self::assertSame(['release', 'notes'], TsqueryLexemes::included("'release' & 'notes'"));
        self::assertSame(['release', 'notes'], TsqueryLexemes::included("'release' <-> 'notes'"));
        self::assertSame(['cherry'], TsqueryLexemes::included("'cherry' & !'durian'"));
        self::assertSame(['a'], TsqueryLexemes::included("'a' & !( 'b' <-> 'c' )"));
        self::assertSame(['d'], TsqueryLexemes::included("!( 'a' | 'b' ) & 'd'"));
        self::assertSame(["it's"], TsqueryLexemes::included("'it''s'"));
        self::assertSame(['pre'], TsqueryLexemes::included("'pre':*"));
        self::assertSame(['x', 'y'], TsqueryLexemes::included("'x' <2> 'y'"));
        self::assertSame([], TsqueryLexemes::included(''));
        self::assertNull(TsqueryLexemes::included("'unterminated"));
        self::assertNull(TsqueryLexemes::included("( 'a'"));
    }

    /** @param array{excerpt:string, matches:list<array{start:int, length:int}>} $result */
    private function slice(array $result, int $index): string
    {
        $utf16 = mb_convert_encoding($result['excerpt'], 'UTF-16LE', 'UTF-8');
        $match = $result['matches'][$index];

        return mb_convert_encoding(substr($utf16, $match['start'] * 2, $match['length'] * 2), 'UTF-8', 'UTF-16LE');
    }
}
