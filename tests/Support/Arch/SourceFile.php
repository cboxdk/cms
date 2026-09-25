<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

use RuntimeException;

/**
 * What the architecture tests need from one PHP file, read with token_get_all.
 *
 * The tokenizer sees every comment, including one that PHPStan never attaches to a node,
 * such as a comment at the end of a file. The tests therefore do not depend on the tools
 * they guard (GUARDRAILS 9, Arkitektur).
 */
final readonly class SourceFile
{
    /**
     * @param  list<DeclaredType>  $types
     * @param  list<Comment>  $comments
     * @param  list<GlobalName>  $globalNames
     */
    public function __construct(
        public string $path,
        public bool $declaresStrictTypes,
        public array $types,
        public array $comments,
        public array $globalNames,
    ) {}

    public static function read(string $path): self
    {
        $code = file_get_contents($path);

        if ($code === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return self::parse($path, $code);
    }

    public static function parse(string $path, string $code): self
    {
        $tokens = self::tokens($code);
        $count = count($tokens);

        $namespace = '';
        $depth = 0;
        $awaitingClassBody = false;
        /** @var list<int> $classBodies */
        $classBodies = [];
        $types = [];
        $comments = [];
        $globalNames = [];

        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $tokens[$i];

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $comments[] = new Comment($namespace, $text, $path, $line);

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

            if ($id === T_NAMESPACE) {
                $next = self::next($tokens, $i);

                if ($next !== null && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $next[1];
                } elseif ($next !== null && $next[1] === '{') {
                    $namespace = '';
                }

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $previous = self::previous($tokens, $i);

                if ($previous !== null && $previous[0] === T_DOUBLE_COLON) {
                    continue;
                }

                $awaitingClassBody = true;
                $next = self::next($tokens, $i);

                if ($next !== null && $next[0] === T_STRING) {
                    $types[] = new DeclaredType($namespace, $next[1], strtolower($text), $path, $line);
                }

                continue;
            }

            if ($id === T_NAME_FULLY_QUALIFIED) {
                $globalNames[] = new GlobalName(ltrim($text, '\\'), $path, $line);

                continue;
            }

            if ($id === T_USE) {
                $inClassBody = $classBodies !== [] && array_last($classBodies) === $depth;
                $next = self::next($tokens, $i);

                if (! $inClassBody && $next !== null && in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $globalNames[] = new GlobalName(ltrim($next[1], '\\'), $path, $next[2]);
                }
            }
        }

        return new self($path, self::startsWithStrictTypes($tokens), $types, $comments, $globalNames);
    }

    /**
     * @return list<array{int, string, int}> id, text and line; a one-character token has id 0
     */
    private static function tokens(string $code): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($code) as $token) {
            if (is_array($token)) {
                $tokens[] = [$token[0], $token[1], $token[2]];
                $line = $token[2] + substr_count($token[1], "\n");
            } else {
                $tokens[] = [0, $token, $line];
            }
        }

        return $tokens;
    }

    /**
     * @param  list<array{int, string, int}>  $tokens
     * @return array{int, string, int}|null
     */
    private static function next(array $tokens, int $index): ?array
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (! self::ignorable($tokens[$i][0])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}>  $tokens
     * @return array{int, string, int}|null
     */
    private static function previous(array $tokens, int $index): ?array
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (! self::ignorable($tokens[$i][0])) {
                return $tokens[$i];
            }
        }

        return null;
    }

    private static function ignorable(int $id): bool
    {
        return in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true);
    }

    /**
     * True when the first statement after the open tag is declare(strict_types=1).
     *
     * @param  list<array{int, string, int}>  $tokens
     */
    private static function startsWithStrictTypes(array $tokens): bool
    {
        if ($tokens === [] || $tokens[0][0] !== T_OPEN_TAG) {
            return false;
        }

        $statement = [];

        foreach ($tokens as $token) {
            if (self::ignorable($token[0])) {
                continue;
            }

            $statement[] = strtolower($token[1]);

            if (count($statement) === 7) {
                break;
            }
        }

        return $statement === ['declare', '(', 'strict_types', '=', '1', ')', ';'];
    }
}
