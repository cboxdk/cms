<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Infrastructure;

use Cbox\Cms\Contracts\Attributes\Internal;
use PhpToken;

/**
 * Reads the names of the classes, interfaces, traits and enums a PHP file declares, from its
 * tokens, without loading it. Anonymous classes have no name and are skipped.
 */
#[Internal]
final readonly class DeclaredClasses
{
    /**
     * @return list<string> fully qualified names, in the order the file declares them
     */
    public static function in(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $namespace = '';
        $names = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_NAMESPACE)) {
                $next = $tokens[$index + 1] ?? null;

                if ($next instanceof PhpToken && $next->is([T_STRING, T_NAME_QUALIFIED])) {
                    $namespace = $next->text;
                } elseif ($next instanceof PhpToken && $next->is(['{', ';'])) {
                    $namespace = '';
                }

                continue;
            }

            if (! $token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                continue;
            }

            $previous = $tokens[$index - 1] ?? null;

            // Foo::class is a constant, not a declaration.
            if ($previous instanceof PhpToken && $previous->is(T_DOUBLE_COLON)) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;

            // An anonymous class is followed by (, {, extends or implements, never by a name.
            if ($name instanceof PhpToken && $name->is(T_STRING)) {
                $names[] = $namespace === '' ? $name->text : $namespace.'\\'.$name->text;
            }
        }

        return $names;
    }
}
