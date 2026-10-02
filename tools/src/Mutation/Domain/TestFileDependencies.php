<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * The test files a set of test files needs besides itself, when a mutation's Pest process loads
 * only some files of its suites (MutationTestFiles).
 *
 * A Pest file may call a function, read a constant, use a class or run a named dataset that
 * another test file of the suites declares at its top level; the whole suite loads every file, so
 * that works there. A process that loaded the file without the one that declares the name would
 * fail, and pest-plugin-mutate would count its mutation as caught. closure() adds, until nothing
 * more is added, every file that declares at its top level a function, constant, class, interface,
 * trait, enum or named dataset whose name one of the files mentions as a word. Mentioning a name is
 * enough, so it may add a file that is not needed, never leave out one that is.
 */
final readonly class TestFileDependencies
{
    /**
     * A top-level declaration of a Pest file: unindented, as Pint writes it.
     */
    private const string DECLARATION = '/^(?:(?:final|abstract|readonly)\s+)*(?:function|const|class|interface|trait|enum)\s+&?([A-Za-z_][A-Za-z0-9_]*)|^dataset\(\s*[\'"]([^\'"]+)[\'"]/m';

    /**
     * The files, in the order given, followed by the files of the suites they need, sorted.
     *
     * @param  list<string>  $selected
     * @param  array<string, string>  $contents  the source of every test file of the suites, by path
     * @return list<string>
     */
    public static function closure(array $selected, array $contents): array
    {
        $declaredIn = self::declarations($contents);
        $included = array_fill_keys($selected, true);
        $queue = $selected;
        $added = [];

        while ($queue !== []) {
            $file = array_shift($queue);

            if (! isset($contents[$file])) {
                continue;
            }

            foreach (self::mentioned($contents[$file], $declaredIn) as $name) {
                foreach ($declaredIn[$name] as $declaring) {
                    if (! isset($included[$declaring])) {
                        $included[$declaring] = true;
                        $added[] = $declaring;
                        $queue[] = $declaring;
                    }
                }
            }
        }

        sort($added, SORT_STRING);

        return [...$selected, ...$added];
    }

    /**
     * For each name a file declares at its top level, the files that declare it.
     *
     * @param  array<string, string>  $contents
     * @return array<string, list<string>>
     */
    public static function declarations(array $contents): array
    {
        $declaredIn = [];

        foreach ($contents as $file => $source) {
            if (preg_match_all(self::DECLARATION, $source, $matches, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($matches as $match) {
                $name = ($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '');

                if ($name !== '' && ! in_array($file, $declaredIn[$name] ?? [], true)) {
                    $declaredIn[$name][] = $file;
                }
            }
        }

        return $declaredIn;
    }

    /**
     * The declared names the source mentions: a name that is an identifier as a word, and a dataset
     * named otherwise in quotes.
     *
     * @param  array<string, list<string>>  $declaredIn
     * @return list<string>
     */
    private static function mentioned(string $source, array $declaredIn): array
    {
        $words = preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $source, $matches) > 0 ? array_fill_keys($matches[0], true) : [];

        return array_values(array_filter(
            array_map(strval(...), array_keys($declaredIn)),
            static fn (string $name): bool => preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) === 1
                ? isset($words[$name])
                : str_contains($source, "'".$name."'") || str_contains($source, '"'.$name.'"'),
        ));
    }
}
