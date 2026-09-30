<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Invariants;

use PhpToken;

/**
 * Reads the tests a Pest file or a PHPUnit class declares from its tokens, without loading it,
 * and finds the tests its source skips.
 *
 * A Pest test is a call of it(), test() or arch() whose first argument is a string without
 * interpolation; its name is that string. A PHPUnit test is a method whose name starts with
 * `test` or that carries the attribute Test; its name is the method's.
 *
 * A test counts as skipped when its call chains skip...() or Pest's pending marker (PENDING), when
 * its body calls markTestSkipped(), markTestIncomplete(), $this->skip...() or the pending marker
 * on $this, or, for a method,
 * when it carries an attribute whose name starts with `Requires`, which skips it where the
 * requirement does not hold. Every test of the file counts as skipped when a statement of
 * beforeEach(), beforeAll(), uses() or pest() does so, when any other code of the file calls
 * markTestSkipped() or markTestIncomplete(), or when the class carries a Requires attribute.
 * Gate 5 runs every suite with --fail-on-skipped and --fail-on-incomplete, which catches the
 * skips a source decides at run time.
 */
final readonly class TestFileReader
{
    private const array PEST_TESTS = ['it', 'test', 'arch'];

    private const array PEST_SETUP = ['beforeEach', 'beforeAll', 'uses', 'pest'];

    /** Pest's method that marks a test as not written yet, which skips it. */
    private const string PENDING = 'to'.'do';

    private const array SKIP_CALLS = ['markTestSkipped', 'markTestIncomplete'];

    private const array OPEN = ['(', '[', '{'];

    private const array CLOSE = [')', ']', '}'];

    public static function read(string $source): TestFile
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
        ));

        $tests = [];
        $skipped = [];
        $skipsAll = false;
        $count = count($tokens);
        $index = 0;

        while ($index < $count) {
            $token = $tokens[$index];

            if ($token->is(T_CLASS) && ! self::anonymousOrConstant($tokens, $index)) {
                $class = self::readClass($tokens, $index);
                array_push($tests, ...$class['tests']);
                array_push($skipped, ...$class['skipped']);
                $skipsAll = $skipsAll || $class['skipsAll'];
                $index = $class['end'] + 1;

                continue;
            }

            if (self::isFunctionCall($tokens, $index, self::PEST_TESTS)) {
                $end = self::statementEnd($tokens, $index + 1);
                $name = self::stringArgument($tokens, $index + 2);

                if ($name !== null) {
                    $tests[] = $name;

                    if (self::chainSkips($tokens, $index + 1, $end) || self::bodySkips($tokens, $index + 2, $end)) {
                        $skipped[] = $name;
                    }
                }

                $index = $end + 1;

                continue;
            }

            if (self::isFunctionCall($tokens, $index, self::PEST_SETUP)) {
                $end = self::statementEnd($tokens, $index + 1);

                if (self::chainSkips($tokens, $index + 1, $end) || self::bodySkips($tokens, $index + 1, $end) || self::skipMethodCalled($tokens, $index + 1, $end)) {
                    $skipsAll = true;
                }

                $index = $end + 1;

                continue;
            }

            if ($token->is(T_STRING) && in_array($token->text, self::SKIP_CALLS, true)) {
                $skipsAll = true;
            }

            $index++;
        }

        return new TestFile($tests, array_values(array_unique($skipped)), $skipsAll);
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return array{tests: list<string>, skipped: list<string>, skipsAll: bool, end: int}
     */
    private static function readClass(array $tokens, int $at): array
    {
        $skipsAll = self::attributesRequire($tokens, $at);
        $open = $at;

        while (isset($tokens[$open]) && $tokens[$open]->text !== '{') {
            $open++;
        }

        $end = self::matching($tokens, $open);
        $tests = [];
        $skipped = [];
        $index = $open + 1;

        while ($index < $end) {
            if (! $tokens[$index]->is(T_FUNCTION)) {
                $index++;

                continue;
            }

            $name = isset($tokens[$index + 1]) ? $tokens[$index + 1]->text : '';
            $body = $index + 1;

            while ($body < $end && $tokens[$body]->text !== '{' && $tokens[$body]->text !== ';') {
                $body++;
            }

            $bodyEnd = $tokens[$body]->text === '{' ? self::matching($tokens, $body) : $body;
            $isTest = str_starts_with($name, 'test') || self::attributesName($tokens, $index, 'Test');
            $bodySkips = self::bodySkips($tokens, $body, $bodyEnd);

            if ($isTest) {
                $tests[] = $name;

                if ($bodySkips || self::attributesRequire($tokens, $index)) {
                    $skipped[] = $name;
                }
            } elseif ($bodySkips) {
                $skipsAll = true;
            }

            $index = $bodyEnd + 1;
        }

        return ['tests' => $tests, 'skipped' => $skipped, 'skipsAll' => $skipsAll, 'end' => $end];
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<string>  $names
     */
    private static function isFunctionCall(array $tokens, int $index, array $names): bool
    {
        return $tokens[$index]->is(T_STRING)
            && in_array($tokens[$index]->text, $names, true)
            && isset($tokens[$index + 1])
            && $tokens[$index + 1]->text === '('
            && ! self::previousIs($tokens, $index, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST]);
    }

    /**
     * Whether the class token at $index is Foo::class or starts an anonymous class, new class or
     * new readonly class.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function anonymousOrConstant(array $tokens, int $index): bool
    {
        return self::previousIs($tokens, $index, [T_DOUBLE_COLON, T_NEW])
            || (self::previousIs($tokens, $index, [T_READONLY]) && self::previousIs($tokens, $index - 1, [T_NEW]));
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @param  list<int>  $kinds
     */
    private static function previousIs(array $tokens, int $index, array $kinds): bool
    {
        return $index > 0 && $tokens[$index - 1]->is($kinds);
    }

    /**
     * The index of the semicolon that ends the statement whose call opens at $open, or the last
     * token.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function statementEnd(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $open; $index < $count; $index++) {
            $depth += self::depthChange($tokens[$index]);

            if ($depth === 0 && $tokens[$index]->text === ';') {
                return $index;
            }
        }

        return $count - 1;
    }

    /**
     * The index of the token that closes the bracket at $open.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($index = $open; $index < $count; $index++) {
            $depth += self::depthChange($tokens[$index]);

            if ($depth === 0) {
                return $index;
            }
        }

        return $count - 1;
    }

    private static function depthChange(PhpToken $token): int
    {
        if (in_array($token->text, self::OPEN, true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE])) {
            return 1;
        }

        return in_array($token->text, self::CLOSE, true) ? -1 : 0;
    }

    /**
     * The string the call's first argument is, when it is one without interpolation.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function stringArgument(array $tokens, int $index): ?string
    {
        $token = $tokens[$index] ?? null;

        if (! $token instanceof PhpToken || ! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $inner = substr($token->text, 1, -1);

        return $token->text[0] === "'"
            ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"])
            : stripcslashes($inner);
    }

    /**
     * Whether a method chained on the call, outside its arguments, skips it.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function chainSkips(array $tokens, int $open, int $end): bool
    {
        $depth = 0;

        for ($index = $open; $index < $end; $index++) {
            $depth += self::depthChange($tokens[$index]);

            if ($depth === 0 && self::isSkipMethod($tokens, $index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the code between $from and $to calls markTestSkipped(), markTestIncomplete(),
     * $this->skip...() or the pending marker on $this.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function bodySkips(array $tokens, int $from, int $to): bool
    {
        for ($index = $from; $index < $to; $index++) {
            if ($tokens[$index]->is(T_STRING) && in_array($tokens[$index]->text, self::SKIP_CALLS, true)) {
                return true;
            }

            if (self::isSkipMethod($tokens, $index) && $index >= 2 && $tokens[$index - 2]->is(T_VARIABLE) && $tokens[$index - 2]->text === '$this') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any method call between $from and $to is skip...() or the pending marker.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function skipMethodCalled(array $tokens, int $from, int $to): bool
    {
        for ($index = $from; $index < $to; $index++) {
            if (self::isSkipMethod($tokens, $index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function isSkipMethod(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        return $token->is(T_STRING)
            && (str_starts_with($token->text, 'skip') || $token->text === self::PENDING)
            && self::previousIs($tokens, $index, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && isset($tokens[$index + 1])
            && $tokens[$index + 1]->text === '(';
    }

    /**
     * The names of the attributes and modifiers before the declaration at $at, back to the end of
     * the previous statement or member.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<string>
     */
    private static function attributeNames(array $tokens, int $at): array
    {
        $names = [];

        for ($index = $at - 1; $index >= 0; $index--) {
            $text = $tokens[$index]->text;

            if (in_array($text, [';', '{', '}'], true)) {
                break;
            }

            if ($tokens[$index]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $parts = explode('\\', $text);
                $names[] = end($parts);
            }
        }

        return $names;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function attributesName(array $tokens, int $at, string $name): bool
    {
        return in_array($name, self::attributeNames($tokens, $at), true);
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function attributesRequire(array $tokens, int $at): bool
    {
        return array_any(self::attributeNames($tokens, $at), static fn (string $name): bool => str_starts_with($name, 'Requires'));
    }
}
