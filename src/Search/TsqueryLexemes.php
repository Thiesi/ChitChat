<?php

declare(strict_types=1);

namespace ChitChat\Search;

/**
 * The lexemes a query asks for, from PostgreSQL's own text form of the parsed tsquery (#283),
 * never from what the person typed. ts_headline also marks words that occur only as negated
 * operands ("apple OR -banana" marks banana), so marks are kept only for these.
 *
 * The canonical form is `'lexeme'` operands (a quote doubled inside), with optional `:*` or
 * weight suffixes, and the operators &, |, !, <->, <N> and parentheses.
 */
final class TsqueryLexemes
{
    /** @return ?list<string> the lexemes not under a negation, or null if the text isn't understood */
    public static function included(string $tsquery): ?array
    {
        $included = [];
        // The negation state of each open parenthesis, and whether the next operand is negated.
        $groups = [false];
        $negateNext = false;
        $length = strlen($tsquery);
        for ($i = 0; $i < $length;) {
            $char = $tsquery[$i];
            if ($char === "'") {
                $lexeme = '';
                $i++;
                while ($i < $length) {
                    if ($tsquery[$i] === "'") {
                        if (($tsquery[$i + 1] ?? '') === "'") {
                            $lexeme .= "'";
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    if ($tsquery[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }
                    $lexeme .= $tsquery[$i];
                    $i++;
                }
                if ($i >= $length) {
                    return null;
                }
                $i++;
                // A prefix or weight suffix such as :* or :AB.
                if (($tsquery[$i] ?? '') === ':') {
                    $i++;
                    while ($i < $length && strpbrk($tsquery[$i], '*ABCDabcd') !== false) {
                        $i++;
                    }
                }
                if (!$negateNext && !end($groups)) {
                    $included[$lexeme] = true;
                }
                $negateNext = false;
                continue;
            }
            if ($char === '!') {
                $negateNext = !$negateNext;
            } elseif ($char === '(') {
                $groups[] = $negateNext || end($groups);
                $negateNext = false;
            } elseif ($char === ')') {
                if (count($groups) === 1) {
                    return null;
                }
                array_pop($groups);
            } elseif ($char === '<') {
                $close = strpos($tsquery, '>', $i);
                if ($close === false) {
                    return null;
                }
                $i = $close;
            } elseif (!in_array($char, ['&', '|', ' '], true)) {
                return null;
            }
            $i++;
        }

        return count($groups) === 1 ? array_map('strval', array_keys($included)) : null;
    }
}
