<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * Reads the functions a PHP file calls, the methods it calls and the classes it names from its
 * tokens, for the egress rule (GUARDRAILS 3).
 *
 * The rule needs exact names: file() is not file_exists(), and $pdo->exec() is not exec(). Pest's
 * not->toUse() matches a dependency as a prefix and sees no method calls, so it cannot tell them
 * apart.
 *
 * A class counts where the file imports it (a trait use included), writes it fully qualified or
 * qualifies it relative to an import. An unqualified name in a namespace is that namespace's own
 * class, so it cannot be a global class unless the file imports it, which already counts. A
 * backtick string is shell_exec().
 *
 * A quoted string whose value reads as a name (a function, a qualified class, or Class::method) is
 * a StringLiteral reference, because PHP calls or resolves it: array_map('file_get_contents', ...),
 * call_user_func('curl_exec', ...), [$info, 'openFile'] and app('GuzzleHttp\Client'). So is a
 * dotted container id such as 'filesystem.disk' or 'mail.manager', which app() resolves. A string used
 * as a key, an array offset such as $found['file'] or an array key before =>, is not a reference:
 * PHP never calls a key. What the scan cannot see is a name built at run time, by concatenation or
 * interpolation; allow_url_fopen=Off in the runtime contract (php.allow_url_fopen) still turns the
 * URL wrappers off there.
 */
final class ReferenceScan
{
    private const array OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    private const array DECLARATIONS = [T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** A name as a string can hold it: a function, a class qualified or not, or Class::method. */
    private const string NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*(?:\\\\|::[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\z/';

    /** A dotted container id: 'filesystem.disk', 'mail.manager', 'auth.password.broker'. */
    private const string SERVICE_ID = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+\z/';

    /**
     * @return list<Reference>
     */
    public static function of(string $path, string $code): array
    {
        $tokens = self::tokens($code);
        $count = count($tokens);
        $references = [];

        $namespace = '';
        /** @var array<string, string> $classes lowercase alias => class */
        $classes = [];
        /** @var array<string, string> $functions lowercase alias => lowercase function */
        $functions = [];

        $depth = 0;
        $awaitingClassBody = false;
        /** @var list<int> $classBodies */
        $classBodies = [];
        $inBackticks = false;

        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $tokens[$i];
            $previous = $tokens[$i - 1] ?? [0, '', 0];
            $next = $tokens[$i + 1] ?? [0, '', 0];

            if ($text === '`') {
                if (! $inBackticks) {
                    $references[] = new Reference(ReferenceKind::Function, 'shell_exec', $namespace, $path, $line);
                }

                $inBackticks = ! $inBackticks;

                continue;
            }

            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                if ($awaitingClassBody && $text === '{') {
                    $classBodies[] = $depth;
                    $awaitingClassBody = false;
                }

                continue;
            }

            if ($text === '}') {
                if ($classBodies !== [] && array_last($classBodies) === $depth) {
                    array_pop($classBodies);
                }

                $depth--;

                continue;
            }

            // A namespace statement; the keyword is also a valid constant name, as in self::NAMESPACE.
            if ($id === T_NAMESPACE && ! in_array($previous[0], [...self::OPERATORS, T_CONST, T_STRING], true)
                && (in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) || $next[1] === '{')) {
                $namespace = $next[1] === '{' ? '' : $next[1];
                $classes = [];
                $functions = [];
                $i += $next[1] === '{' ? 0 : 1;

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                if ($previous[0] !== T_DOUBLE_COLON) {
                    $awaitingClassBody = true;
                }

                continue;
            }

            if ($id === T_USE) {
                if ($next[1] === '(') {
                    continue;
                }

                $end = self::statementEnd($tokens, $i);

                if ($classBodies !== [] && array_last($classBodies) === $depth) {
                    foreach (array_slice($tokens, $i + 1, $end - $i - 1) as $token) {
                        if (in_array($token[0], self::NAMES, true)) {
                            $references[] = new Reference(ReferenceKind::ClassName, self::resolveClass($token, $namespace, $classes), $namespace, $path, $token[2]);
                        }
                    }
                } else {
                    foreach (self::imports(array_slice($tokens, $i + 1, $end - $i - 1)) as [$kind, $name, $alias, $importLine]) {
                        if ($kind === T_FUNCTION) {
                            $functions[strtolower($alias)] = strtolower($name);
                        } elseif ($kind === T_USE) {
                            $classes[strtolower($alias)] = $name;
                            $references[] = new Reference(ReferenceKind::ClassName, $name, $namespace, $path, $importLine);
                        }
                    }
                }

                // A trait use with a block ends at its brace, which the depth count still needs.
                $i = $tokens[$end][1] === '{' ? $end - 1 : $end;

                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = self::unquote($text);

                if ((preg_match(self::NAME, $value) === 1 || preg_match(self::SERVICE_ID, $value) === 1) && ! self::isKey($tokens, $i)) {
                    $references[] = new Reference(ReferenceKind::StringLiteral, ltrim($value, '\\'), $namespace, $path, $line);
                }

                continue;
            }

            if (! in_array($id, self::NAMES, true)) {
                continue;
            }

            $called = $next[1] === '(' && $previous[0] !== T_NEW && $previous[0] !== T_ATTRIBUTE;

            if ($id === T_STRING && in_array($previous[0], self::OPERATORS, true)) {
                if ($next[1] === '(') {
                    $references[] = new Reference(ReferenceKind::Method, strtolower($text), $namespace, $path, $line);
                }

                continue;
            }

            if (in_array($previous[0], self::DECLARATIONS, true)
                || ($previous[1] === '&' && ($tokens[$i - 2][0] ?? 0) === T_FUNCTION)) {
                continue;
            }

            if ($id === T_STRING) {
                if ($called) {
                    $references[] = new Reference(ReferenceKind::Function, $functions[strtolower($text)] ?? strtolower($text), $namespace, $path, $line);
                } elseif ($namespace === '' && ($previous[0] === T_NEW || $previous[0] === T_INSTANCEOF || $next[0] === T_DOUBLE_COLON)) {
                    $references[] = new Reference(ReferenceKind::ClassName, $text, $namespace, $path, $line);
                }

                continue;
            }

            if ($called) {
                $function = $id === T_NAME_FULLY_QUALIFIED ? ltrim($text, '\\') : self::resolveClass($tokens[$i], $namespace, $classes);
                $references[] = new Reference(ReferenceKind::Function, strtolower($function), $namespace, $path, $line);

                continue;
            }

            $references[] = new Reference(ReferenceKind::ClassName, self::resolveClass($tokens[$i], $namespace, $classes), $namespace, $path, $line);
        }

        return $references;
    }

    /**
     * A class name resolved through the imports and the namespace, without a leading backslash.
     *
     * @param  array{int, string, int}  $token
     * @param  array<string, string>  $classes
     */
    private static function resolveClass(array $token, string $namespace, array $classes): string
    {
        [$id, $text] = $token;

        if ($id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }

        if ($id === T_NAME_RELATIVE) {
            return ltrim($namespace.'\\'.substr($text, strlen('namespace\\')), '\\');
        }

        $segments = explode('\\', $text, 2);
        $imported = $classes[strtolower($segments[0])] ?? null;

        if ($imported !== null) {
            return isset($segments[1]) ? $imported.'\\'.$segments[1] : $imported;
        }

        return $namespace === '' ? $text : $namespace.'\\'.$text;
    }

    /**
     * Whether the string at $index is a key: an array key before =>, or the offset in $a['x'],
     * $a[0]['x'], f()['x'] or $a->b['x'], where the bracket follows a value.
     *
     * @param  list<array{int, string, int}>  $tokens
     */
    private static function isKey(array $tokens, int $index): bool
    {
        $next = $tokens[$index + 1] ?? [0, '', 0];

        if ($next[0] === T_DOUBLE_ARROW) {
            return true;
        }

        $open = $tokens[$index - 1] ?? [0, '', 0];
        $value = $tokens[$index - 2] ?? [0, '', 0];

        return $open[1] === '[' && $next[1] === ']'
            && ($value[0] === T_VARIABLE || $value[0] === T_STRING || in_array($value[1], [']', ')', '}'], true));
    }

    /**
     * The value of a quoted string literal. Only the escapes a name can contain matter: a
     * backslash written as two, and in double quotes a backslash before any other character stays.
     */
    private static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return str_starts_with($literal, "'")
            ? strtr($body, ['\\\\' => '\\', "\\'" => "'"])
            : strtr($body, ['\\\\' => '\\', '\\"' => '"', '\\$' => '$']);
    }

    /**
     * The imports of one use statement, given the tokens between `use` and its semicolon: the kind
     * (T_USE for a class, T_FUNCTION or T_CONST), the full name, the alias and the line.
     *
     * @param  list<array{int, string, int}>  $tokens
     * @return list<array{int, string, string, int}>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $statementKind = T_USE;

        if (in_array($tokens[0][0] ?? 0, [T_FUNCTION, T_CONST], true)) {
            $statementKind = $tokens[0][0];
            $tokens = array_slice($tokens, 1);
        }

        $prefix = '';
        $kind = $statementKind;
        $name = null;
        $alias = null;
        $line = 0;
        $expectAlias = false;

        foreach ([...$tokens, [0, ';', 0]] as [$id, $text, $tokenLine]) {
            if (in_array($id, self::NAMES, true)) {
                if ($expectAlias) {
                    $alias = $text;
                    $expectAlias = false;
                } else {
                    $name = $text;
                    $line = $tokenLine;
                }
            } elseif ($id === T_FUNCTION || $id === T_CONST) {
                $kind = $id;
            } elseif ($id === T_NS_SEPARATOR) {
                $prefix = ($name ?? '').'\\';
                $name = null;
            } elseif ($id === T_AS) {
                $expectAlias = true;
            } elseif (in_array($text, [',', '}', ';'], true)) {
                if ($name !== null) {
                    $full = ltrim($prefix.$name, '\\');
                    $segments = explode('\\', $full);
                    $imports[] = [$kind, $full, $alias ?? array_last($segments), $line];
                }

                $name = null;
                $alias = null;
                $kind = $statementKind;
            }
        }

        return $imports;
    }

    /**
     * The index of the semicolon that ends the use statement at $index, or of the brace that opens
     * the block of a trait use. The braces of a group import do not end it.
     *
     * @param  list<array{int, string, int}>  $tokens
     */
    private static function statementEnd(array $tokens, int $index): int
    {
        $groups = 0;

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $text = $tokens[$i][1];

            if ($text === '{') {
                if ($tokens[$i - 1][0] !== T_NS_SEPARATOR) {
                    return $i;
                }

                $groups++;
            } elseif ($text === '}') {
                $groups--;
            } elseif ($text === ';' && $groups === 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * The tokens that carry meaning: no whitespace, comments or open tag. A one-character token
     * has id 0.
     *
     * @return list<array{int, string, int}>
     */
    private static function tokens(string $code): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                    $tokens[] = [$token[0], $token[1], $line];
                }

                $line += substr_count($token[1], "\n");
            } else {
                $tokens[] = [0, $token, $line];
            }
        }

        return $tokens;
    }
}
