<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Phpstan;
use Examples\Postgres\Harness\RealServicesTest;
use Symfony\Component\Process\Process;

/*
 * Gate 5 of GUARDRAILS 10: the Pest suites Unit, Codecs, Contract, Postgres, Arch and Actions,
 * the Browser suite of gate 8 and the Mutation suite of the PR profile. These tests guard the
 * layout itself (GUARDRAILS 7.3): every test file is in exactly one suite, the Postgres and Actions
 * suites hold the tests below a Postgres or Actions directory and nothing else does, and the
 * harness trait is applied to the Postgres directories.
 */

/**
 * The suites in phpunit.xml with their directories and excludes.
 *
 * @return array<string, array{directories: list<string>, excludes: list<string>}>
 */
function configuredSuites(): array
{
    $xml = simplexml_load_file(Phpstan::root().'/phpunit.xml');

    if ($xml === false) {
        throw new UnexpectedValueException('phpunit.xml is not valid XML.');
    }

    $suites = [];

    foreach ($xml->testsuites->testsuite as $suite) {
        $directories = [];
        $excludes = [];

        foreach ($suite->directory as $directory) {
            $directories[] = (string) $directory;
        }

        foreach ($suite->exclude as $exclude) {
            $excludes[] = (string) $exclude;
        }

        $suites[(string) $suite['name']] = ['directories' => $directories, 'excludes' => $excludes];
    }

    return $suites;
}

/**
 * The Pest test classes of one suite, or of all suites when $suite is null.
 *
 * @return list<string>
 */
function listedTests(?string $suite): array
{
    $command = [PHP_BINARY, 'vendor/bin/pest', '--list-tests', '--colors=never'];

    if ($suite !== null) {
        $command[] = '--testsuite='.$suite;
    }

    $process = new Process($command, Phpstan::root(), null, null, 120);
    $process->run();

    preg_match_all('/^ - (\S+?)::/m', $process->getOutput(), $matches);

    return array_values(array_unique($matches[1]));
}

/**
 * The test class of each file named like a test below tests, examples and each package's tests directory,
 * outside the fixture directories: the class of the file's name that a PHPUnit file declares, or
 * the name Pest gives a file of test functions.
 *
 * @return list<string>
 */
function testFilesOnDisk(): array
{
    $root = Phpstan::root();
    $classes = [];

    foreach ([$root.'/tests', $root.'/examples', ...(glob($root.'/packages/*/tests', GLOB_ONLYDIR) ?: [])] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);

            if (! str_ends_with($path, 'Test.php') || str_contains($path, '/Fixtures/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $name = basename($path, '.php');

            if (preg_match('/^(?:final |abstract |readonly )*class '.$name.'\\b/m', $source) === 1) {
                preg_match('/^namespace ([^;]+);/m', $source, $namespace);
                $classes[] = ($namespace[1] ?? '').'\\'.$name;
            } else {
                $classes[] = 'P\\'.str_replace('/', '\\', ucfirst(substr($path, 0, -strlen('.php'))));
            }
        }
    }

    sort($classes);

    return $classes;
}

it('defines the suites of gate 5, Unit, Codecs, Contract, Postgres, Arch and Actions, Browser for gate 8, and Mutation for the PR profile\'s gate 5', function (): void {
    expect(configuredSuites())->toBe([
        'Unit' => [
            'directories' => ['tests/Feature', 'examples/Unit', 'packages/*/tests'],
            'excludes' => ['packages/*/tests/Codecs', 'packages/*/tests/Contract', 'packages/*/tests/Postgres', 'packages/*/tests/Actions'],
        ],
        'Codecs' => ['directories' => ['tests/Codecs', 'examples/Codecs', 'packages/*/tests/Codecs'], 'excludes' => []],
        'Contract' => ['directories' => ['tests/Contract', 'examples/Contract', 'packages/*/tests/Contract'], 'excludes' => []],
        'Postgres' => ['directories' => ['tests/Postgres', 'examples/Postgres', 'packages/*/tests/Postgres'], 'excludes' => []],
        'Arch' => ['directories' => ['tests/Arch'], 'excludes' => []],
        'Actions' => ['directories' => ['tests/Actions', 'packages/*/tests/Actions'], 'excludes' => []],
        'Browser' => ['directories' => ['tests/Browser'], 'excludes' => []],
        'Mutation' => ['directories' => ['tests/Mutation'], 'excludes' => []],
    ]);
});

it('puts every test in exactly one suite, only Postgres tests in the Postgres suite and only action tests in the Actions suite', function (): void {
    $bySuite = [];

    foreach (array_keys(configuredSuites()) as $suite) {
        $bySuite[$suite] = listedTests($suite);
    }

    $inSuites = array_merge(...array_values($bySuite));
    $all = listedTests(null);
    sort($inSuites);
    sort($all);

    // A PHPUnit class below examples/Postgres is named Examples\Postgres\..., with nothing before it.
    $isPostgres = static fn (string $class): bool => preg_match('/(?:^|\\\\)(Tests|tests|Examples)\\\\Postgres\\\\/', $class) === 1;
    $isActions = static fn (string $class): bool => preg_match('/\\\\(Tests|tests)\\\\Actions\\\\/', $class) === 1;

    expect($all)->not->toBeEmpty()
        ->and($inSuites)->toBe($all)
        ->and($all)->toBe(testFilesOnDisk())
        ->and($bySuite['Postgres'])->not->toBeEmpty()
        ->and(array_values(array_filter($bySuite['Postgres'], static fn (string $class): bool => ! $isPostgres($class))))->toBe([])
        ->and(array_values(array_filter($bySuite['Unit'], $isPostgres)))->toBe([])
        ->and($bySuite['Postgres'])->toContain('P\Tests\Postgres\RolesTest', 'P\Packages\testkit\tests\Postgres\HarnessTest', RealServicesTest::class)
        ->and(array_values(array_filter($bySuite['Actions'], static fn (string $class): bool => ! $isActions($class))))->toBe([])
        ->and(array_values(array_filter($bySuite['Unit'], $isActions)))->toBe([])
        ->and($bySuite['Actions'])->toContain(
            'P\Packages\core\tests\Actions\BuildRegistryTest',
            'P\Packages\core\tests\Actions\MaintainPartitionsTest',
            'P\Packages\core\tests\Actions\RunDoctorTest',
            'P\Packages\generators\tests\Actions\GenerateCodeTest',
        )
        ->and($bySuite['Browser'])->toBe(['P\Tests\Browser\WorkbenchPageTest']);
});

it('boots the workbench application for the browser tests', function (): void {
    $pest = (string) file_get_contents(Phpstan::root().'/tests/Pest.php');

    expect($pest)->toContain("pest()->extend(TestCase::class)->in('Feature', 'Codecs', 'Contract', 'Postgres', 'Actions', 'Browser', '../packages/*/tests', '../examples');");
});

it('applies the real-Postgres harness to the Postgres directories', function (): void {
    $pest = (string) file_get_contents(Phpstan::root().'/tests/Pest.php');

    expect($pest)->toContain("pest()->use(RealPostgres::class)->in('Postgres', '../packages/*/tests/Postgres', '../examples/Postgres');");
});

it('applies the real-Valkey harness to the Postgres directories', function (): void {
    $pest = (string) file_get_contents(Phpstan::root().'/tests/Pest.php');

    expect($pest)->toContain("pest()->use(RealValkey::class)->in('Postgres', '../packages/*/tests/Postgres', '../examples/Postgres');");
});
