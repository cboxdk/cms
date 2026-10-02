<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Mutation;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Boundary\GitMutationScope;
use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;

/*
 * Mutation on changed files for real (M0-T47): the steps MutationSteps builds run Pest's
 * --mutate with PCOV on a scratch repository that uses this checkout's vendor, and the report
 * plugin in composer.json's extra.pest.plugins gives each class its score. A test that runs a
 * branch without asserting what it returns scores below 80 and fails the step, naming the class;
 * the assertion makes it pass. It needs PCOV, which the php container and the CI image have and
 * the host does not, so it is the Mutation suite, which the PR profile runs in gate 5.
 *
 * The class is an adapter and its test is in the Postgres suite, so the step that runs here is
 * the one with the Postgres suite, with --parallel as the PR profile runs it. Pest's parallel
 * workers take the directory above the real path of the vendor that holds Pest for the project,
 * so the scratch repository has a vendor of its own (scratchVendor()): Composer's autoloader,
 * the bin proxies and Pest copied, and every other package a symlink to this checkout's. The PR
 * profile runs the fast suites' step on this checkout. Without the fast suites' report, the step
 * with Postgres counts its own run only, and runs rather than passing on the fast suites' word.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * A repository laid out like this one: phpunit.xml with the suites and packages/*\/src as the
 * source, the checkout's vendor, and tools/mutation/pcov.ini, committed without a package.
 */
function mutationRepository(): ScratchRepository
{
    $root = Phpstan::root();
    $suites = implode("\n", array_map(
        static fn (string $suite): string => "        <testsuite name=\"{$suite}\"><directory suffix=\"Test.php\">tests/{$suite}</directory></testsuite>",
        [...MutationSteps::FAST_SUITES, MutationSteps::POSTGRES_SUITE],
    ));
    $repository = ScratchRepository::make('cbox-cms-mutation-test-')
        ->write('phpunit.xml', <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit bootstrap="bootstrap.php" colors="false" cacheDirectory=".phpunit.cache">
                <testsuites>
            {$suites}
                </testsuites>
                <source>
                    <include>
                        <directory>packages/*/src</directory>
                    </include>
                </source>
            </phpunit>
            XML)
        ->write('bootstrap.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            require __DIR__.'/vendor/autoload.php';

            spl_autoload_register(static function (string $class): void {
                $prefix = 'Acme\\Parity\\';

                if (str_starts_with($class, $prefix)) {
                    require __DIR__.'/packages/parity/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
                }
            });
            PHP)
        ->write('.gitignore', "/vendor/\n/tools/src\n/.phpunit.cache/\n")
        ->write(MutationSteps::PCOV_INI_DIRECTORY.'/pcov.ini', (string) file_get_contents($root.'/'.MutationSteps::PCOV_INI_DIRECTORY.'/pcov.ini'));

    foreach ([...MutationSteps::FAST_SUITES, MutationSteps::POSTGRES_SUITE] as $suite) {
        $repository->write("tests/{$suite}/.gitkeep", '');
    }

    scratchVendor($root, $repository->root);
    $repository->commit('the layout, without a package');

    return $repository;
}

/**
 * A vendor for the repository at $target that loads Pest from inside $target, so that Pest's
 * parallel workers take $target for the project: vendor/autoload.php, vendor/composer, vendor/bin
 * and vendor/pestphp/pest are copies, so the paths they resolve stay below $target, and every
 * other package is a symlink into this checkout's vendor. The copied autoloader maps the root
 * package's own namespaces below $target, so tools/src, which holds the report plugin, is a
 * symlink to this checkout's too.
 */
function scratchVendor(string $root, string $target): void
{
    $copied = ['autoload.php', 'bin', 'composer'];
    mkdir($target.'/vendor/pestphp', 0o777, true);

    foreach ((array) scandir($root.'/vendor') as $entry) {
        if (! is_string($entry) || in_array($entry, ['.', '..', 'pestphp'], true)) {
            continue;
        }

        in_array($entry, $copied, true)
            ? copyTree($root.'/vendor/'.$entry, $target.'/vendor/'.$entry)
            : symlink($root.'/vendor/'.$entry, $target.'/vendor/'.$entry);
    }

    foreach ((array) scandir($root.'/vendor/pestphp') as $entry) {
        if (! is_string($entry) || in_array($entry, ['.', '..'], true)) {
            continue;
        }

        $entry === 'pest'
            ? copyTree($root.'/vendor/pestphp/pest', $target.'/vendor/pestphp/pest')
            : symlink($root.'/vendor/pestphp/'.$entry, $target.'/vendor/pestphp/'.$entry);
    }

    symlink($root.'/tools/src', $target.'/tools/src');
}

/**
 * Copies the file or directory $from to $to, with the permissions of each file.
 */
function copyTree(string $from, string $to): void
{
    if (! is_dir($from)) {
        copy($from, $to);
        chmod($to, (int) fileperms($from) & 0o777);

        return;
    }

    mkdir($to, 0o777, true);

    foreach ((array) scandir($from) as $entry) {
        if (is_string($entry) && ! in_array($entry, ['.', '..'], true)) {
            copyTree($from.'/'.$entry, $to.'/'.$entry);
        }
    }
}

/**
 * Runs the step with Postgres of mutation on changed files since the given base in the repository.
 */
function runMutation(ScratchRepository $repository, string $baseRef): ?StepResult
{
    $steps = MutationSteps::for(GitMutationScope::resolve($repository->root, $baseRef), PHP_BINARY);

    expect(array_map(static fn (Step $step): string => $step->name, $steps))->toBe([MutationSteps::FAST_NAME, MutationSteps::POSTGRES_NAME]);

    $report = new CheckRunner(new SymfonyProcessRunner(600.0), new SilentMutationListener)->run([new Gate(5, 'Pest', [$steps[1]])], $repository->root);

    return $report->gate(5)?->step(MutationSteps::POSTGRES_NAME);
}

/**
 * Runs both steps of mutation on changed files since the given base in the repository, and gives
 * the step with Postgres, which judges every class over both runs.
 */
function runFastMutation(ScratchRepository $repository, string $baseRef): ?StepResult
{
    $steps = MutationSteps::for(GitMutationScope::resolve($repository->root, $baseRef), PHP_BINARY);

    expect(array_map(static fn (Step $step): string => $step->name, $steps))->toBe([MutationSteps::FAST_NAME, MutationSteps::POSTGRES_NAME]);

    $report = new CheckRunner(new SymfonyProcessRunner(600.0), new SilentMutationListener)->run([new Gate(5, 'Pest', $steps)], $repository->root);

    return $report->gate(5)?->step(MutationSteps::POSTGRES_NAME);
}

it('fails the step below 80 and names the class when a test leaves a branch unasserted, and passes once it is asserted', function (): void {
    expect(extension_loaded('pcov'))->toBeTrue('The Mutation suite needs PCOV, as in the php container and the CI image: docker compose exec php vendor/bin/pest --testsuite=Mutation');

    $repository = mutationRepository();
    $repository
        ->write('packages/parity/src/Adapter/Parity.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Acme\Parity\Adapter;

            final readonly class Parity
            {
                public static function of(int $number): string
                {
                    if ($number % 2 === 0) {
                        return 'even';
                    }

                    return sprintf('odd, next %d, half %d, square %d', $number + 1, intdiv($number, 2), $number * $number);
                }
            }
            PHP)
        ->write('tests/Postgres/ParityTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Acme\Parity\Adapter\Parity;

            it('names numbers', function (): void {
                expect(Parity::of(4))->toBe('even');

                // The odd branch runs, but nothing asserts what it returns.
                Parity::of(7);
            });
            PHP)
        ->commit('Parity, with the odd branch unasserted');

    $unasserted = runMutation($repository, 'HEAD~1');

    expect($unasserted?->status)->toBe(StepStatus::Fail, $unasserted->output ?? '')
        ->and($unasserted?->reason)->toMatch('/^below 80% over its mutations: Acme\\\\Parity\\\\Adapter\\\\Parity \d+\.\d\d%$/')
        ->and($unasserted?->notes[0] ?? '')->toStartWith('Acme\Parity\Adapter\Parity: ')
        ->and($unasserted?->notes)->toContain('the fast suites\' run recorded no report, so only this run counts');

    $repository
        ->write('tests/Postgres/ParityTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Acme\Parity\Adapter\Parity;

            it('names numbers', function (): void {
                expect(Parity::of(4))->toBe('even')
                    ->and(Parity::of(7))->toBe('odd, next 8, half 3, square 49');
            });
            PHP)
        ->commit('assert the odd branch');

    $asserted = runMutation($repository, 'HEAD~2');

    expect($asserted?->status)->toBe(StepStatus::Pass, $asserted->output ?? '')
        ->and($asserted?->reason)->toBeNull()
        ->and($asserted?->notes[0] ?? '')->toMatch('/^Acme\\\\Parity\\\\Adapter\\\\Parity: (8\d|9\d|100)\.\d\d%, \d+ of \d+ mutations caught$/');
});

it('tests a class that more tests cover than one argument can name, as a service provider every test boots is', function (): void {
    expect(extension_loaded('pcov'))->toBeTrue('The Mutation suite needs PCOV, as in the php container and the CI image: docker compose exec php vendor/bin/pest --testsuite=Mutation');

    // pest-plugin-mutate names every test that covers a mutation in one --filter argument. 800
    // tests with long names make it longer than Linux lets one argument be (MAX_ARG_STRLEN, 128
    // KiB), and the process for the mutation could not start: "Argument list too long".
    $repository = mutationRepository();
    $repository
        ->write('packages/parity/src/Greeting.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Acme\Parity;

            final readonly class Greeting
            {
                public static function to(string $name): string
                {
                    return 'Hello, '.$name;
                }
            }
            PHP)
        ->write('tests/Unit/GreetingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Acme\Parity\Greeting;

            for ($number = 0; $number < 800; $number++) {
                it(sprintf('greets the name it is given, number %04d, %s', $number, str_repeat('with a description long enough to fill the filter ', 3)), function (): void {
                    expect(Greeting::to('Ada'))->toBe('Hello, Ada');
                });
            }
            PHP)
        ->commit('Greeting, covered by 800 tests');

    $step = runFastMutation($repository, 'HEAD~1');

    // Every mutation of Greeting was caught by the fast suites, so the step with Postgres passed
    // without running the Postgres suite.
    expect($step?->status)->toBe(StepStatus::Pass, $step->output ?? '')
        ->and($step?->notes[0] ?? '')->toMatch('/^the fast suites caught all \d+ mutations of the changed sources, so the Postgres suite cannot change the score and is not run$/');
});
