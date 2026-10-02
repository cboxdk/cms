<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ParallelWorker;
use Cbox\Cms\Tooling\Mutation\Boundary\SuiteTestFiles;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTestFiles;
use Cbox\Cms\Tooling\Mutation\Domain\TestFileDependencies;
use Cbox\Cms\Tooling\Mutation\Domain\TestFilterWidth;
use Pest\Mutate\Plugins\Mutate;
use Symfony\Component\Process\Process;

/*
 * Each mutation's Pest process loaded every test file of its suites to run the few tests its
 * --filter names, about a second of every process of the mutation run of gate 5. The report
 * plugin now gives it the files of those suites whose class the filter can name instead
 * (MutationTestFiles), so it runs the same tests. tests/Mutation runs the steps for real.
 */

/**
 * @param  list<string>  $suites
 * @return array<string, string>
 */
function suiteFilesFor(array $suites): array
{
    return match ($suites) {
        ['Unit', 'Arch'] => [
            'tests/Feature/FooTest.php' => "<?php\n\nit('a', fn () => expect(barHelper())->toBe(1));\n",
            'tests/Feature/XFooTest.php' => "<?php\n\nit('b', fn () => expect(true)->toBeTrue());\n",
            'tests/Feature/BarTest.php' => "<?php\n\nfunction barHelper(): int\n{\n    return 1;\n}\n",
            'tests/Arch/FooBarTest.php' => "<?php\n\narch('c', fn () => expect(true)->toBeTrue());\n",
        ],
        default => [],
    };
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 */
function runFastSuites(array $command, array $environment): Process
{
    $process = new Process($command, Phpstan::root(), array_merge(ParallelWorker::cleared(), $environment), null, 300);
    $process->run();

    return $process;
}

it('reads the classes the entries of a filter name, as TestFilterWidth writes them', function (): void {
    $filter = '"'.implode('|', [
        TestFilterWidth::entry('P\Tests\Feature\FooTest::__pest_evaluable_it_names__the_thing'),
        TestFilterWidth::entry('Cbox\Cms\Tests\Feature\BarTest::testItTicks#with data'),
        TestFilterWidth::entry('P\Tests\Feature\FooTest::'),
    ]).'"';

    expect(MutationTestFiles::classes($filter))->toBe(['BarTest', 'FooTest'])
        ->and(MutationTestFiles::classes('"FooTest::(.*)it.a|::(.*)it.b"'))->toBeNull()
        ->and(MutationTestFiles::classes('"::(.*)it.b"'))->toBeNull()
        ->and(MutationTestFiles::classes('"FooTest"'))->toBeNull()
        ->and(MutationTestFiles::classes(''))->toBeNull()
        ->and(MutationTestFiles::classes('"FooTest::(.*)calls.Other\\Name::(.*)x"'))->toBe(['FooTest']);
});

it('keeps the files whose name ends with a class of the filter, since the filter matches the end of a class name', function (): void {
    $files = array_keys(suiteFilesFor(['Unit', 'Arch']));

    expect(MutationTestFiles::select($files, ['FooTest']))
        ->toBe(['tests/Feature/FooTest.php', 'tests/Feature/XFooTest.php'])
        ->and(MutationTestFiles::select($files, ['BarTest', 'FooTest']))
        ->toBe(['tests/Feature/FooTest.php', 'tests/Feature/XFooTest.php', 'tests/Feature/BarTest.php', 'tests/Arch/FooBarTest.php']);
});

it('replaces the suites with the files the filter can select tests from', function (): void {
    $arguments = ['vendor/bin/pest', '--testsuite=Unit,Arch', '--fail-on-skipped', '--bail', '--filter="FooTest::(.*)it.a"'];

    expect(MutationTestFiles::narrow($arguments, suiteFilesFor(...)))->toBe([
        'vendor/bin/pest', '--fail-on-skipped', '--bail', '--filter="FooTest::(.*)it.a"', 'tests/Feature/FooTest.php', 'tests/Feature/XFooTest.php', 'tests/Feature/BarTest.php',
    ]);
});

it('narrows nothing without suites, without a filter, for an entry of any class, or when no file matches',
    /**
     * @param  list<string>  $arguments
     */
    function (array $arguments): void {
        $arguments = array_values(array_filter($arguments, is_string(...)));
        expect(MutationTestFiles::narrow($arguments, suiteFilesFor(...)))->toBe($arguments);
    })->with([
        'no suites' => [['vendor/bin/pest', '--bail', '--filter="FooTest::(.*)it.a"']],
        'no filter' => [['vendor/bin/pest', '--testsuite=Unit,Arch', '--bail']],
        'an entry without a class' => [['vendor/bin/pest', '--testsuite=Unit,Arch', '--filter="FooTest::(.*)it.a|::(.*)it.b"']],
        'no file of the class' => [['vendor/bin/pest', '--testsuite=Unit,Arch', '--filter="QuuxTest::(.*)it.a"']],
        'suites without files' => [['vendor/bin/pest', '--testsuite=Codecs', '--filter="FooTest::(.*)it.a"']],
    ]);

it('lists the test files of the suites as phpunit.xml selects them', function (): void {
    $root = Phpstan::root();
    $unit = SuiteTestFiles::of($root, ['Unit']);
    $both = SuiteTestFiles::of($root, ['Unit', 'Arch']);

    expect($unit)->toContain('tests/Feature/Tooling/Mutation/MutationTestFilesTest.php', 'packages/core/tests/Seeding/EntryGeneratorTest.php')
        ->and($unit)->not->toContain('tests/Arch/LayersTest.php', 'packages/core/tests/Actions/SeedDatasetTest.php', 'tests/Feature/Tooling/Mutation/MutationListener.php')
        ->and($both)->toContain('tests/Arch/LayersTest.php')
        ->and($both)->toBe(array_values(array_unique($both)));
});

it('runs the same tests in a mutation\'s process as with every file of the suites', function (): void {
    $root = Phpstan::root();
    $source = $root.'/tools/src/Mutation/Domain/MutationTestFiles.php';
    $command = [PHP_BINARY, 'vendor/bin/pest', '--testsuite='.implode(',', MutationSteps::FAST_SUITES), '--bail', '--filter="TestFilterWidthTest::(.*)|ChangedSourceTest::(.*)"'];
    $everything = runFastSuites($command, []);
    $narrowed = runFastSuites($command, [Mutate::ENV_MUTATION_TESTING => $source, Mutate::ENV_MUTATION_FILE => $source]);
    $summary = static fn (Process $process): string => preg_match('/Tests:\s+(\d+ passed \(\d+ assertions\))/', (string) preg_replace('/\e\[[0-9;]*m/', '', $process->getOutput()), $match) === 1 ? $match[1] : $process->getOutput().$process->getErrorOutput();

    expect($everything->getExitCode())->toBe(0, $everything->getOutput())
        ->and($narrowed->getExitCode())->toBe(0, $narrowed->getOutput())
        ->and($summary($narrowed))->toBe($summary($everything))
        ->and($summary($everything))->toMatch('/^\d+ passed/');
});

it('loads every suite of gate 5 without an issue outside a test, so a mutation\'s process whose tests pass exits 0', function (): void {
    // pest-plugin-mutate counts a mutation as caught when its process exits other than 0. A PHP
    // warning while PHPUnit loads a test file, such as a `use` of a global class in the global
    // namespace, fails every run that loads the file under failOnWarning, so every mutation its
    // suite covered counted as caught. The parallel runs of the gate do not report it.
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', '--testsuite='.implode(',', [...MutationSteps::FAST_SUITES, MutationSteps::POSTGRES_SUITE]), '--filter=TestFilterWidthTest'],
        Phpstan::root(),
        ParallelWorker::cleared(),
        null,
        300,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});

it('adds the test files that declare what the kept files use: functions, constants, classes and named datasets', function (): void {
    $contents = [
        'tests/UsesTest.php' => "<?php\n\nit('a', fn () => expect(helper() + LIMIT)->toBe(new Shape)->with('pairs')->with('two words'));\n",
        'tests/HelperTest.php' => "<?php\n\nfunction helper(): int\n{\n    return nested();\n}\n",
        'tests/NestedTest.php' => "<?php\n\nfunction nested(): int\n{\n    return 1;\n}\n",
        'tests/LimitTest.php' => "<?php\n\nconst LIMIT = 2;\n",
        'tests/ShapeTest.php' => "<?php\n\nfinal readonly class Shape {}\n",
        'tests/PairsTest.php' => "<?php\n\ndataset('pairs', [[1, 2]]);\n",
        'tests/WordsTest.php' => "<?php\n\ndataset('two words', [[1]]);\n",
        'tests/UnrelatedTest.php' => "<?php\n\nfunction unrelated(): void {}\n",
    ];

    expect(TestFileDependencies::closure(['tests/UsesTest.php'], $contents))->toBe([
        'tests/UsesTest.php',
        'tests/HelperTest.php',
        'tests/LimitTest.php',
        'tests/NestedTest.php',
        'tests/PairsTest.php',
        'tests/ShapeTest.php',
        'tests/WordsTest.php',
    ])->and(TestFileDependencies::closure(['tests/UnrelatedTest.php'], $contents))->toBe(['tests/UnrelatedTest.php']);
});
