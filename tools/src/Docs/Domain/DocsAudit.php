<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The check behind gate 10 of GUARDRAILS 10, `composer docs:check` (tools/bin/docs-check.php): a
 * public extension point without a page and a running example fails, and the code on the pages
 * runs as tests (GUARDRAILS 2.4, PRD 14.4).
 *
 * The inventory (Inventory, less Exclusions) comes from the declarations in packages/<package>/src
 * and the schemas in packages/<package>/resources/schemas, read from tokens with no autoloading.
 *
 * Pages. A page is a Markdown file below packages/<package>/docs or
 * packages/<package>/resources/schemas. There is no central list; a page declares what it documents:
 *
 *   <!-- extension-point: Cbox\Cms\Contracts\Clock -->
 *   <!-- extension-point: packages/contracts/resources/schemas/blueprint.v1.json -->
 *
 * Every extension point is on exactly one page. Every page has at least one example, a marker
 * followed on the next line by a fenced block whose content is the file byte for byte:
 *
 *   <!-- example: examples/Contract/Clock/SystemClockTest.php -->
 *
 * The file is a *Test.php that one of the gate-5 suites of phpunit.xml includes
 * (LocalProfile::SUITES), and it asserts: it calls expect() or an assert method, or uses a trait of
 * the inventory, as a shared contract suite's test class does. A support file or fixture is
 * embedded with `<!-- example-file: <path> -->` and the same byte-for-byte check, and its name
 * without the extension, a class short name or a fixture name, appears in an example test on the
 * same page. Every fenced block on a page is one of these embeds; a command goes in inline code.
 * Every path in a marker is repo-relative.
 *
 * Examples tree. The examples live in examples/<Suite>/<Topic>/, and phpunit.xml adds
 * examples/Unit, examples/Codecs, examples/Contract and examples/Postgres to those suites. Composer
 * maps Examples\ to examples/ (autoload-dev), and PHPStan, Rector and Pint cover the tree. An
 * example PHP file is a Pest file in the global namespace or a class below Examples\, never in
 * Cbox\Cms, so the testkit's cboxCms.internalUse rule reports any use of #[Internal] API and an
 * example shows only what an application or addon may use. Every *Test.php below examples/ is
 * embedded by a page.
 */
final readonly class DocsAudit
{
    public const string EXAMPLES = 'examples';

    public const string EXAMPLES_NAMESPACE = 'Examples';

    private const string KERNEL_NAMESPACE = 'Cbox\Cms';

    /**
     * Every finding for the tree, sorted (Finding::sorted()); none when the check passes.
     *
     * @param  list<Exclusion>  $exclusions
     * @return list<Finding>
     */
    public static function findings(DocsTree $tree, array $exclusions): array
    {
        $inventory = Inventory::of($tree->sources, $tree->schemas, $exclusions);
        $findings = $inventory->findings;
        /** @var array<string, list<Marker>> $documented where each extension point is documented, by name */
        $documented = [];
        $documentedOn = [];
        /** @var array<string, true> $embeddedExamples */
        $embeddedExamples = [];

        foreach ($tree->pages as $page) {
            foreach ($page->markers(MarkerKind::ExtensionPoint) as $marker) {
                if ($inventory->has($marker->target)) {
                    $documented[$marker->target][] = $marker;
                    $documentedOn[$marker->target][] = $page->path;
                } elseif (array_key_exists($marker->target, $inventory->excluded)) {
                    $findings[] = Finding::at($page->path, $marker->line, "names {$marker->target} as an extension point, but the inventory excludes it: {$inventory->excluded[$marker->target]}");
                } else {
                    $findings[] = Finding::at($page->path, $marker->line, "names {$marker->target} as an extension point, but the inventory has no such interface, attribute class, trait, #[Command] or #[Hook] class or schema that is not #[Internal]");
                }
            }

            foreach ($page->markers(MarkerKind::Example) as $marker) {
                if ($marker->isRepoRelativePath()) {
                    $embeddedExamples[$marker->target] = true;
                }
            }

            array_push($findings, ...self::pageFindings($page, $tree, $inventory));
        }

        foreach ($documented as $name => $markers) {
            $first = $markers[0];
            $firstPage = $documentedOn[$name][0];

            foreach (array_slice($markers, 1, null, true) as $index => $marker) {
                $findings[] = Finding::at($documentedOn[$name][$index], $marker->line, "{$name} is also documented on {$firstPage}:{$first->line}; every extension point is on exactly one page");
            }
        }

        foreach ($inventory->points as $name => $point) {
            if (! array_key_exists($name, $documented)) {
                $findings[] = Finding::about($name, 'undocumented');
            }
        }

        foreach ($tree->examples as $example) {
            array_push($findings, ...self::exampleFindings($example, $embeddedExamples));
        }

        return Finding::sorted($findings);
    }

    /**
     * @return list<Finding>
     */
    private static function pageFindings(Page $page, DocsTree $tree, Inventory $inventory): array
    {
        $findings = [];

        if ($page->markers(MarkerKind::Example) === []) {
            $findings[] = Finding::at($page->path, 1, 'the page has no example; add <!-- example: <repo-relative path> --> with the fenced *Test.php below it');
        }

        foreach ($page->markersWithoutBlock() as $marker) {
            $findings[] = Finding::at($page->path, $marker->line, "{$marker->text()} is not followed immediately by a fenced block");
        }

        foreach ($page->strayFences as $line) {
            $findings[] = Finding::at($page->path, $line, 'the fenced block is no checked embed; put a command in inline code, and embed a file with <!-- example: <path> --> or <!-- example-file: <path> --> on the line above');
        }

        foreach ($page->unclosedFences as $line) {
            $findings[] = Finding::at($page->path, $line, 'the fenced block is not closed');
        }

        /** @var list<string> $exampleSources the contents of the page's example tests that exist */
        $exampleSources = [];

        foreach ($page->embeds as $embed) {
            $marker = $embed->marker;

            if (! $marker->isRepoRelativePath()) {
                $findings[] = Finding::at($page->path, $marker->line, "{$marker->target} is not a repo-relative path");

                continue;
            }

            $contents = $tree->files->contents($marker->target);

            if ($contents === null) {
                $findings[] = Finding::at($page->path, $marker->line, "{$marker->target} does not exist");

                continue;
            }

            if ($embed->body !== $contents) {
                $findings[] = Finding::at($page->path, $marker->line, "the fenced block differs from {$marker->target}; embed the file byte for byte");
            }

            if ($marker->kind === MarkerKind::Example) {
                $exampleSources[] = $contents;
                array_push($findings, ...self::exampleTestFindings($page, $marker, $tree, $inventory));
            }
        }

        foreach ($page->embeds(MarkerKind::ExampleFile) as $embed) {
            $marker = $embed->marker;

            if (! $marker->isRepoRelativePath() || $tree->files->contents($marker->target) === null) {
                continue;
            }

            $name = pathinfo($marker->target, PATHINFO_FILENAME);
            $pattern = '/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/';

            if (! array_any($exampleSources, static fn (string $source): bool => preg_match($pattern, $source) === 1)) {
                $findings[] = Finding::at($page->path, $marker->line, "no example test on this page mentions {$name}, so nothing shows {$marker->target} in use");
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private static function exampleTestFindings(Page $page, Marker $marker, DocsTree $tree, Inventory $inventory): array
    {
        $findings = [];

        if (! str_ends_with($marker->target, SuiteDirectory::SUFFIX)) {
            $findings[] = Finding::at($page->path, $marker->line, "{$marker->target} is not a *Test.php; embed a support file or fixture with <!-- example-file: <path> -->");
        } elseif (! $tree->suites->includes($marker->target)) {
            $findings[] = Finding::at($page->path, $marker->line, "{$marker->target} is in none of the gate-5 suites of phpunit.xml ({$tree->suites->names()}), so it never runs");
        }

        $php = $tree->files->php($marker->target);

        if ($php instanceof PhpFile && ! self::asserts($php, $inventory)) {
            $findings[] = Finding::at($page->path, $marker->line, "{$marker->target} has no assertion: it calls neither expect() nor an assert method and uses no trait of the inventory");
        }

        return $findings;
    }

    private static function asserts(PhpFile $file, Inventory $inventory): bool
    {
        return array_any($file->calls, static fn (string $call): bool => strcasecmp($call, 'expect') === 0 || stripos($call, 'assert') === 0)
            || array_any($file->traitUses, $inventory->isTrait(...));
    }

    /**
     * @param  array<string, true>  $embeddedExamples
     * @return list<Finding>
     */
    private static function exampleFindings(PhpFile $example, array $embeddedExamples): array
    {
        $findings = [];

        foreach ($example->namespaces as $namespace) {
            if ($namespace->name === self::KERNEL_NAMESPACE || str_starts_with($namespace->name, self::KERNEL_NAMESPACE.'\\')) {
                $findings[] = Finding::at($example->path, $namespace->line, "the example is in the namespace {$namespace->name}; an example is a Pest file in the global namespace or a class below Examples\\, so it shows only what an application or addon may use and never #[Internal] API");
            } elseif ($namespace->name !== '' && $namespace->name !== self::EXAMPLES_NAMESPACE && ! str_starts_with($namespace->name, self::EXAMPLES_NAMESPACE.'\\')) {
                $findings[] = Finding::at($example->path, $namespace->line, "the example is in the namespace {$namespace->name}; an example is a Pest file in the global namespace or a class below Examples\\");
            }
        }

        if (str_ends_with($example->path, SuiteDirectory::SUFFIX) && ! array_key_exists($example->path, $embeddedExamples)) {
            $findings[] = Finding::at($example->path, 1, "no page embeds this example; embed it on the page of what it shows with <!-- example: {$example->path} -->");
        }

        return $findings;
    }
}
