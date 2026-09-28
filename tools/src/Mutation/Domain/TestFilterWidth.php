<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * Keeps the --filter argument of every mutation's Pest process within what one argument may be.
 *
 * pest-plugin-mutate 5 starts one Pest process per mutation, with the tests that covered the
 * mutated lines as a single argument, `--filter="<Class>::(.*)<test>|..."`: an entry per test,
 * made from the test's id in the coverage data (entry()). Linux refuses to start a process whose
 * argument is longer than MAX_ARG_STRLEN, 128 KiB with its closing NUL, and the mutation then
 * fails with "Argument list too long". A source that nearly every test runs, such as a service
 * provider that every test boots, has more covering tests than that.
 *
 * widen() looks at each source file of the coverage data: when the entries of all the tests that
 * cover any of its lines, the most any one mutation in it can name, would make the argument longer
 * than ARGUMENT_BYTES, it gives each line of that file the test classes of its tests instead, as
 * ids `<Class>::`, whose entry `<Class>::(.*)` selects every test of the class. That runs at least
 * the tests that covered the line, so every mutation a covering test catches is still caught; it
 * only runs more tests, and a file's argument is then bounded by its covering test classes. The
 * other files keep their tests, and their processes run exactly what they ran before.
 */
final readonly class TestFilterWidth
{
    /** Linux's MAX_ARG_STRLEN: the most bytes one argument may have, its closing NUL included. */
    public const int ARGUMENT_BYTES = 131072;

    /** The argument around the entries, as MutationTest::start() writes it. */
    public const string ARGUMENT_PREFIX = '--filter="';

    public const string ARGUMENT_SUFFIX = '"';

    /**
     * The entry pest-plugin-mutate 5 makes of a test's id for the filter, as MutationTest::start()
     * makes it, or null for an id it skips.
     */
    public static function entry(string $test): ?string
    {
        if (preg_match('/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/', $test, $matches) !== 1) {
            return null;
        }

        if ($matches[2] === '__pest_evaluable_') {
            return $matches[1].'::(.*)'.str_replace(['__', '_'], ['.{1,2}', '.'], $matches[3]);
        }

        return $matches[1].'::(.*)'.$matches[3];
    }

    /**
     * The bytes of the --filter argument that names the given test ids, its closing NUL included.
     *
     * @param  list<string>  $testIds
     */
    public static function argumentBytes(array $testIds): int
    {
        $entries = [];

        foreach ($testIds as $test) {
            $entry = self::entry($test);

            if ($entry !== null) {
                $entries[$entry] = true;
            }
        }

        return strlen(self::ARGUMENT_PREFIX.implode('|', array_keys($entries)).self::ARGUMENT_SUFFIX) + 1;
    }

    /**
     * The id that names every test of the class of the test with the id $test, or $test itself
     * when it names no class.
     */
    public static function classOf(string $test): string
    {
        $separator = strpos($test, '::');

        return $separator === false ? $test : substr($test, 0, $separator + 2);
    }

    /**
     * The coverage data with each file whose covering tests would make too long an argument given
     * the classes of its tests instead (see the class comment).
     *
     * @param  array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>  $lineCoverage  file, line, test index and hits
     * @param  array<int<0, max>, non-empty-string>  $testIds  test index and test id
     */
    public static function widen(array $lineCoverage, array $testIds): WidenedCoverage
    {
        $widenedFiles = [];
        $classIndexes = array_flip($testIds);
        $nextIndex = $testIds === [] ? 0 : max(array_keys($testIds)) + 1;

        foreach ($lineCoverage as $file => $lines) {
            $covering = [];

            foreach ($lines as $hits) {
                foreach (array_keys($hits ?? []) as $index) {
                    if (isset($testIds[$index])) {
                        $covering[$index] = $testIds[$index];
                    }
                }
            }

            if (self::argumentBytes(array_values($covering)) <= self::ARGUMENT_BYTES) {
                continue;
            }

            foreach ($lines as $line => $hits) {
                if ($hits === null) {
                    continue;
                }

                $classHits = [];

                foreach (array_keys($hits) as $index) {
                    $class = self::classOf($testIds[$index] ?? '');

                    if ($class === '') {
                        continue;
                    }

                    if (! isset($classIndexes[$class])) {
                        $classIndexes[$class] = $nextIndex;
                        $testIds[$nextIndex] = $class;
                        $nextIndex++;
                    }

                    $classHits[$classIndexes[$class]] = 1;
                }

                $lineCoverage[$file][$line] = $classHits;
            }

            $widenedFiles[] = $file;
        }

        return new WidenedCoverage($lineCoverage, $testIds, $widenedFiles);
    }
}
