<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * Gate 5 of GUARDRAILS 10: the Pest suites Unit, Codecs, Contract, Postgres and Arch, and the
 * Browser suite of gate 8. These tests guard the layout itself (GUARDRAILS 7.3): every test file
 * is in exactly one suite, the Postgres suite holds the tests below a Postgres directory and
 * nothing else does, and the harness trait is applied to those directories.
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

it('defines the suites of gate 5, Unit, Codecs, Contract, Postgres and Arch, and Browser for gate 8', function (): void {
    expect(configuredSuites())->toBe([
        'Unit' => [
            'directories' => ['tests/Feature', 'packages/*/tests'],
            'excludes' => ['packages/*/tests/Codecs', 'packages/*/tests/Contract', 'packages/*/tests/Postgres'],
        ],
        'Codecs' => ['directories' => ['tests/Codecs', 'packages/*/tests/Codecs'], 'excludes' => []],
        'Contract' => ['directories' => ['tests/Contract', 'packages/*/tests/Contract'], 'excludes' => []],
        'Postgres' => ['directories' => ['tests/Postgres', 'packages/*/tests/Postgres'], 'excludes' => []],
        'Arch' => ['directories' => ['tests/Arch'], 'excludes' => []],
        'Browser' => ['directories' => ['tests/Browser'], 'excludes' => []],
    ]);
});

it('puts every test in exactly one suite, and only Postgres tests in the Postgres suite', function (): void {
    $bySuite = [];

    foreach (array_keys(configuredSuites()) as $suite) {
        $bySuite[$suite] = listedTests($suite);
    }

    $inSuites = array_merge(...array_values($bySuite));
    $all = listedTests(null);
    sort($inSuites);
    sort($all);

    $isPostgres = static fn (string $class): bool => preg_match('/\\\\(Tests|tests)\\\\Postgres\\\\/', $class) === 1;

    expect($all)->not->toBeEmpty()
        ->and($inSuites)->toBe($all)
        ->and($bySuite['Postgres'])->not->toBeEmpty()
        ->and(array_values(array_filter($bySuite['Postgres'], static fn (string $class): bool => ! $isPostgres($class))))->toBe([])
        ->and(array_values(array_filter($bySuite['Unit'], $isPostgres)))->toBe([])
        ->and($bySuite['Postgres'])->toContain('P\Tests\Postgres\RolesTest', 'P\Packages\testkit\tests\Postgres\HarnessTest')
        ->and($bySuite['Browser'])->toBe(['P\Tests\Browser\WorkbenchPageTest']);
});

it('boots the workbench application for the browser tests', function (): void {
    $pest = (string) file_get_contents(Phpstan::root().'/tests/Pest.php');

    expect($pest)->toContain("pest()->extend(TestCase::class)->in('Feature', 'Codecs', 'Contract', 'Postgres', 'Browser', '../packages/*/tests');");
});

it('applies the real-Postgres harness to the Postgres directories', function (): void {
    $pest = (string) file_get_contents(Phpstan::root().'/tests/Pest.php');

    expect($pest)->toContain("pest()->use(RealPostgres::class)->in('Postgres', '../packages/*/tests/Postgres');");
});

it('applies the real-Valkey harness to the Postgres directories', function (): void {
    $pest = (string) file_get_contents(Phpstan::root().'/tests/Pest.php');

    expect($pest)->toContain("pest()->use(RealValkey::class)->in('Postgres', '../packages/*/tests/Postgres');");
});
