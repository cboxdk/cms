<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Affected\Domain;

use PhpToken;

/**
 * Whether a `*Test.php` file is a PHPUnit test class or a Pest file. Pest's test impact analysis
 * takes Pest files only and stops the whole run at the first PHPUnit class
 * (Pest\Subscribers\EnsureTiaIsRunningPestTestsOnly), so `composer test:affected` runs the two
 * apart (tools/bin/test-affected.php).
 *
 * A file is a PHPUnit test class when it declares a class named as the file, as PHPUnit requires,
 * such as `final class FakeClockContractTest extends TestCase` in FakeClockContractTest.php. A
 * Pest file may declare helper classes, but none named as its file.
 */
enum TestFileKind
{
    case PhpunitClass;
    case Pest;

    public static function of(string $path, string $code): self
    {
        $name = basename($path, '.php');
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_CLASS)) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;

            if ($previous instanceof PhpToken && $previous->is([T_DOUBLE_COLON, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR])) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;

            if ($next instanceof PhpToken && $next->is(T_STRING) && $next->text === $name) {
                return self::PhpunitClass;
            }
        }

        return self::Pest;
    }
}
