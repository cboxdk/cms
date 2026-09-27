<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\DeclaredType;
use Cbox\Cms\Tooling\Docs\Domain\NamespaceDeclaration;
use Cbox\Cms\Tooling\Docs\Domain\PhpFile;
use Cbox\Cms\Tooling\Docs\Domain\TypeKind;
use PhpToken;

/**
 * Reads a PHP file from its tokens, without loading it, so the check works on a tree whose classes
 * no autoloader knows: its namespace statements; the named types it declares with the attributes
 * on each, resolved through the file's use imports as PHP resolves them; the traits that class,
 * trait and enum bodies use, anonymous classes included; and the names it calls.
 */
final class PhpTokens
{
    /** @var list<PhpToken> the tokens without whitespace and comments */
    private array $tokens;

    private string $namespace = '';

    /** @var array<string, string> the imported class names by lowercase alias */
    private array $imports = [];

    /** @var list<NamespaceDeclaration> */
    private array $namespaces = [];

    /** @var list<DeclaredType> */
    private array $types = [];

    /** @var list<string> */
    private array $traitUses = [];

    /** @var array<string, true> */
    private array $calls = [];

    private function __construct(string $source)
    {
        $this->tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    /**
     * @param  string  $path  repo-relative
     */
    public static function read(string $path, string $source): PhpFile
    {
        $reader = new self($source);
        $reader->scan();

        return new PhpFile($path, $reader->namespaces, $reader->types, array_values(array_unique($reader->traitUses)), array_keys($reader->calls));
    }

    private function scan(): void
    {
        $depth = 0;
        /** @var list<int> $bodies the depths at which the open class, trait and enum bodies start */
        $bodies = [];
        $bodyPending = false;
        /** @var list<string> $attributes the attributes read since the last declaration */
        $attributes = [];
        $count = count($this->tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $this->tokens[$index];

            if ($token->is(T_ATTRIBUTE)) {
                [$names, $index] = $this->attributeGroup($index);
                array_push($attributes, ...$names);

                continue;
            }

            if ($token->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
                continue;
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && ! $this->previousIs($index, T_DOUBLE_COLON)) {
                $name = $this->tokens[$index + 1] ?? null;

                if ($name instanceof PhpToken && $name->is(T_STRING)) {
                    $this->types[] = new DeclaredType($this->qualify($name->text), $this->kind($token), $attributes, $token->line);
                }

                $attributes = [];
                $bodyPending = true;

                continue;
            }

            $attributes = [];

            if ($token->is(T_NAMESPACE)) {
                $index = $this->namespaceStatement($index);
            } elseif ($token->is(T_USE)) {
                if ($bodies !== [] && end($bodies) === $depth) {
                    $index = $this->traitUse($index);
                } elseif ($bodies === [] && ! $this->nextIs($index, '(')) {
                    $index = $this->importStatement($index);
                }
            } elseif ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                if ($bodyPending) {
                    $bodies[] = $depth;
                    $bodyPending = false;
                }
            } elseif ($token->is('}')) {
                if ($bodies !== [] && end($bodies) === $depth) {
                    array_pop($bodies);
                }

                $depth--;
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && $this->nextIs($index, '(') && ! $this->previousIs($index, T_FUNCTION)) {
                $segments = explode('\\', $token->text);
                $this->calls[end($segments)] = true;
            }
        }
    }

    /**
     * The attribute names of the group that opens at $index, and the index of its closing bracket.
     *
     * @return array{list<string>, int}
     */
    private function attributeGroup(int $index): array
    {
        $names = [];
        $brackets = 1;
        $parentheses = 0;
        $expectName = true;
        $count = count($this->tokens);

        for ($index++; $index < $count; $index++) {
            $token = $this->tokens[$index];

            if ($token->is(['[', T_ATTRIBUTE])) {
                $brackets++;
            } elseif ($token->is(']') && --$brackets === 0) {
                break;
            } elseif ($token->is('(')) {
                $parentheses++;
            } elseif ($token->is(')')) {
                $parentheses--;
            } elseif ($brackets === 1 && $parentheses === 0) {
                if ($token->is(',')) {
                    $expectName = true;
                } elseif ($expectName && $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                    $names[] = $this->resolve($token);
                    $expectName = false;
                }
            }
        }

        return [$names, $index];
    }

    /**
     * Reads `namespace Name;` or `namespace Name {` and returns the index of its last token read.
     */
    private function namespaceStatement(int $index): int
    {
        $name = $this->tokens[$index + 1] ?? null;
        $this->imports = [];

        if ($name instanceof PhpToken && $name->is([T_STRING, T_NAME_QUALIFIED])) {
            $this->namespace = $name->text;
            $this->namespaces[] = new NamespaceDeclaration($name->text, $this->tokens[$index]->line);

            return $index + 1;
        }

        $this->namespace = '';

        return $index;
    }

    /**
     * Reads `use A\B;`, `use A\B as C, D;` and `use A\{B, C as D};`, skipping function and constant
     * imports, and returns the index of the semicolon.
     */
    private function importStatement(int $index): int
    {
        $count = count($this->tokens);
        $kind = $this->tokens[$index + 1] ?? null;
        $skip = $kind instanceof PhpToken && $kind->is([T_FUNCTION, T_CONST]);
        $prefix = '';
        $name = null;

        for ($index++; $index < $count; $index++) {
            $token = $this->tokens[$index];

            if ($token->is(';')) {
                break;
            }

            if ($skip) {
                continue;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($token->text, '\\');
                $alias = $this->tokens[$index + 1] ?? null;

                if ($alias instanceof PhpToken && $alias->is(T_AS)) {
                    $index += 2;
                    $as = $this->tokens[$index] ?? null;
                    $this->import($prefix.$name, $as instanceof PhpToken ? $as->text : $name);
                    $name = null;
                }
            } elseif ($token->is(T_NS_SEPARATOR) && $name !== null) {
                $prefix = $name.'\\';
                $name = null;
            } elseif ($token->is([',', '}'])) {
                if ($name !== null) {
                    $this->import($prefix.$name, $name);
                }

                $name = null;
                $prefix = $token->is('}') ? '' : $prefix;
            }
        }

        if ($name !== null) {
            $this->import($prefix.$name, $name);
        }

        return $index;
    }

    private function import(string $name, string $alias): void
    {
        $segments = explode('\\', $alias);
        $this->imports[strtolower(end($segments))] = $name;
    }

    /**
     * Reads `use A, B;` or `use A, B { ... }` in a class body and returns the index of the token
     * before the semicolon or brace, so the brace is counted.
     */
    private function traitUse(int $index): int
    {
        $count = count($this->tokens);

        for ($index++; $index < $count; $index++) {
            $token = $this->tokens[$index];

            if ($token->is([';', '{'])) {
                return $index - 1;
            }

            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $this->traitUses[] = $this->resolve($token);
            }
        }

        return $index;
    }

    private function resolve(PhpToken $token): string
    {
        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($token->text, '\\');
        }

        if ($token->is(T_NAME_RELATIVE)) {
            return $this->qualify(substr($token->text, strlen('namespace\\')));
        }

        $segments = explode('\\', $token->text, 2);
        $imported = $this->imports[strtolower($segments[0])] ?? null;

        if ($imported !== null) {
            return $imported.(isset($segments[1]) ? '\\'.$segments[1] : '');
        }

        return $this->qualify($token->text);
    }

    private function qualify(string $name): string
    {
        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    private function previousIs(int $index, int|string $kind): bool
    {
        return $index > 0 && $this->tokens[$index - 1]->is($kind);
    }

    private function nextIs(int $index, int|string $kind): bool
    {
        return isset($this->tokens[$index + 1]) && $this->tokens[$index + 1]->is($kind);
    }

    private function kind(PhpToken $token): TypeKind
    {
        return match (true) {
            $token->is(T_INTERFACE) => TypeKind::Interface,
            $token->is(T_TRAIT) => TypeKind::Trait,
            $token->is(T_ENUM) => TypeKind::Enum,
            default => TypeKind::Class_,
        };
    }
}
