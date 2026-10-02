<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Gives the declarations of a mutated source that no coverage driver can cover the tests that
 * run the code they declare values for.
 *
 * pest-plugin-mutate mutates a class constant, an enum case, a property's default and an
 * attribute's arguments like any other code, and runs a mutation only with the tests that covered
 * its lines. PCOV never marks those lines, because PHP does not execute them, so every such
 * mutation was reported as uncovered and counted against the class: a class of constants alone
 * scored 0, and a test that asserts the value could not help. A value declared there is read where
 * the code names it: in its own file, through `self::`, `static::` or reflection of the class, and
 * in other files as `<Class>::<name>`. attribute() gives each line of those declarations
 * the tests that covered any line of the file, and the tests that covered a line of another file
 * that names the class's short name before `::`, besides any tests the line already had. A
 * constant expression that PHP evaluates at run time, such as an array that spreads another
 * constant, is marked once per process, for whichever test first read it, so such a line gets the
 * other tests as well (M1-T66: a list of names whose own tests never ran for its mutations). It
 * changes no other line, so every mutation elsewhere runs as before.
 */
final readonly class DeclarationCoverage
{
    /**
     * The short class names a line names before `::`.
     */
    private const string REFERENCE = '/(?<![A-Za-z0-9_$])([A-Za-z_][A-Za-z0-9_]*)::/';

    /**
     * @param  array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>  $lineCoverage  file, line, test index and hits
     * @param  array<non-empty-string, string>  $contents  the source of each file of the coverage data and of each mutated source, by absolute path
     * @param  list<non-empty-string>  $mutated  the absolute paths of the mutated sources
     * @return array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>
     */
    public static function attribute(array $lineCoverage, array $contents, array $mutated): array
    {
        $referencing = self::references($lineCoverage, $contents);

        foreach ($mutated as $file) {
            $source = $contents[$file] ?? null;

            if ($source === null) {
                continue;
            }

            $lines = self::declarationLines($source);

            if ($lines === []) {
                continue;
            }

            $tests = self::testsOf($lineCoverage[$file] ?? []);

            foreach (self::classNames($source) as $class) {
                $tests += $referencing[$class] ?? [];
            }

            if ($tests === []) {
                continue;
            }

            ksort($tests);

            foreach ($lines as $line) {
                $existing = $lineCoverage[$file][$line] ?? [];
                $lineCoverage[$file][$line] = $existing + $tests;
                ksort($lineCoverage[$file][$line]);
            }
        }

        return $lineCoverage;
    }

    /**
     * The lines of the class constants, enum cases, properties and attributes of a source, sorted.
     *
     * @return list<int<1, max>>
     */
    public static function declarationLines(string $source): array
    {
        $statements = new ParserFactory()->createForHostVersion()->parse($source) ?? [];
        $finder = new NodeFinder;
        $lines = [];

        foreach ($finder->find($statements, static fn (Node $node): bool => $node instanceof ClassConst || $node instanceof EnumCase || $node instanceof Property || $node instanceof AttributeGroup) as $node) {
            $start = $node->getStartLine();
            $end = $node->getEndLine();

            if ($start < 1 || $end < $start) {
                continue;
            }

            foreach (range($start, $end) as $line) {
                $lines[$line] = true;
            }
        }

        $lines = array_keys($lines);
        sort($lines);

        return $lines;
    }

    /**
     * The short names of the classes, interfaces, traits and enums a source declares.
     *
     * @return list<string>
     */
    public static function classNames(string $source): array
    {
        $statements = new ParserFactory()->createForHostVersion()->parse($source) ?? [];
        $names = [];

        foreach (new NodeFinder()->findInstanceOf($statements, Node\Stmt\ClassLike::class) as $class) {
            if ($class->name !== null) {
                $names[] = $class->name->toString();
            }
        }

        return $names;
    }

    /**
     * For each short class name, the tests that covered a line that names it before `::`.
     *
     * @param  array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>  $lineCoverage
     * @param  array<non-empty-string, string>  $contents
     * @return array<string, array<int<0, max>, int<1, max>>>
     */
    private static function references(array $lineCoverage, array $contents): array
    {
        $references = [];

        foreach ($lineCoverage as $file => $lines) {
            if (! isset($contents[$file])) {
                continue;
            }

            $text = explode("\n", $contents[$file]);

            foreach ($lines as $line => $hits) {
                if ($hits === null || $hits === [] || preg_match_all(self::REFERENCE, $text[$line - 1] ?? '', $matches) < 1) {
                    continue;
                }

                foreach ($matches[1] as $class) {
                    $references[$class] = ($references[$class] ?? []) + array_fill_keys(array_keys($hits), 1);
                }
            }
        }

        return $references;
    }

    /**
     * @param  array<int<1, max>, array<int<0, max>, int<1, max>>|null>  $lines
     * @return array<int<0, max>, int<1, max>>
     */
    private static function testsOf(array $lines): array
    {
        $tests = [];

        foreach ($lines as $hits) {
            foreach (array_keys($hits ?? []) as $index) {
                $tests[$index] = 1;
            }
        }

        return $tests;
    }
}
