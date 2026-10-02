<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Closure;

/**
 * Names the test files a mutation's Pest process loads, instead of every file of its suites.
 *
 * pest-plugin-mutate 5 starts one Pest process per mutation with the arguments of the run, among
 * them `--testsuite=<suites>`, and `--filter="<Class>::(.*)<test>|..."` naming the tests that
 * covered the mutated lines (TestFilterWidth). PHPUnit then loads every test file of the suites
 * to find the few the filter selects, which took about a second of each process before the first
 * test ran, and with thousands of mutations most of the run. narrow() replaces the suites with
 * the files of those suites whose tests the filter can select, so the process runs the same tests
 * after loading a handful of files.
 *
 * The filter selects a test by its id, `<namespace>\<Class>::<name>`, and PHPUnit matches it
 * anywhere in the id, so an entry `<Class>::(.*)...` selects tests of every class whose name ends
 * with `<Class>`. A test class is named after its file, in Pest as in PSR-4, so the files kept are
 * those of the suites whose name without `.php` ends with the class of an entry: every file that
 * holds a test the filter can select, and perhaps a few more, whose tests the filter leaves out.
 * The test files those need, for a function, constant, class or dataset another test file declares,
 * are added (TestFileDependencies).
 * An entry without a class selects tests of any class, and then nothing is narrowed; neither is
 * it without a filter, without suites, or when no file of the suites matches.
 */
final readonly class MutationTestFiles
{
    public const string SUITE_OPTION = '--testsuite=';

    public const string FILTER_OPTION = '--filter=';

    /**
     * The class of an entry: the word before `::(.*)`, as TestFilterWidth::entry() writes it.
     */
    private const string ENTRY_CLASS = '/(?<![A-Za-z0-9_\\\\])([A-Za-z0-9]*)::\(\.\*\)/';

    /**
     * The arguments of a mutation's Pest process with `--testsuite=` replaced by the files the
     * filter can select tests from, or the arguments as they are when nothing can be narrowed.
     *
     * @param  list<string>  $arguments
     * @param  Closure(list<string>): array<string, string>  $suiteFiles  the source of each test file of the named suites, by path relative to the root
     * @return list<string>
     */
    public static function narrow(array $arguments, Closure $suiteFiles): array
    {
        $suites = null;
        $classes = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, self::SUITE_OPTION)) {
                $suites = array_values(array_filter(
                    explode(',', substr($argument, strlen(self::SUITE_OPTION))),
                    static fn (string $suite): bool => $suite !== '',
                ));
            }

            if (str_starts_with($argument, self::FILTER_OPTION)) {
                $classes = self::classes(substr($argument, strlen(self::FILTER_OPTION)));
            }
        }

        if ($suites === null || $suites === [] || $classes === null) {
            return $arguments;
        }

        $contents = $suiteFiles($suites);
        $files = self::select(array_map(strval(...), array_keys($contents)), $classes);

        if ($files === []) {
            return $arguments;
        }

        $files = TestFileDependencies::closure($files, $contents);

        return [
            ...array_values(array_filter(
                $arguments,
                static fn (string $argument): bool => ! str_starts_with($argument, self::SUITE_OPTION),
            )),
            ...$files,
        ];
    }

    /**
     * The classes the entries of a filter name, each once and sorted, or null when an entry can
     * select a test of any class: an entry without a class, or a filter with no entry.
     *
     * @return list<string>|null
     */
    public static function classes(string $filter): ?array
    {
        if (preg_match_all(self::ENTRY_CLASS, $filter, $matches) === false || $matches[1] === []) {
            return null;
        }

        $classes = [];

        foreach ($matches[1] as $class) {
            if ($class === '') {
                return null;
            }

            $classes[$class] = true;
        }

        $classes = array_keys($classes);
        sort($classes, SORT_STRING);

        return array_map(strval(...), $classes);
    }

    /**
     * The files whose name without `.php` ends with one of the classes, in the order given.
     *
     * @param  list<string>  $files
     * @param  list<string>  $classes
     * @return list<string>
     */
    public static function select(array $files, array $classes): array
    {
        return array_values(array_filter(
            $files,
            static fn (string $file): bool => array_any(
                $classes,
                static fn (string $class): bool => str_ends_with(basename($file), $class.'.php'),
            ),
        ));
    }
}
