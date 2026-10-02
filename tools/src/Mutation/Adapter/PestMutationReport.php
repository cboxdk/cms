<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Boundary\SuiteTestFiles;
use Cbox\Cms\Tooling\Mutation\Domain\MutationTestFiles;
use Pest\Contracts\Plugins\Bootable;
use Pest\Contracts\Plugins\HandlesArguments;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Plugins\Mutate;
use Pest\Plugins\Parallel;

/**
 * A Pest plugin, listed in composer.json's extra.pest.plugins, that lets mutation on changed
 * files (Cbox\Cms\Tooling\Mutation\Domain\MutationSteps) read the score of each changed file.
 * Pest prints only the score of the whole run, and with --parallel not even which file a mutation
 * belongs to. With CMS_MUTATION_REPORT=1 in the environment, the Pest process that runs the
 * mutations prints MutationReportPrinter's report line when they are done. In every --mutate run,
 * with the variable or without, it lets CoverageFilterWidener keep each mutation's --filter
 * argument within what one argument may be, and with CaughtByFastSuites' variable it leaves the
 * mutations the fast suites caught out of the run (SkipCaughtMutations); it turns PCOV off for
 * the processes that test the mutations (CoverageOffForMutations). With the variable, it also
 * removes the files a mutation left in the checkout when the run ends (CheckoutGuard), so the
 * next step's run starts from the checkout as it was. It does nothing else in the
 * processes that run a worker's tests. In a process that tests one mutation, it gives PHPUnit the test files the
 * mutation's --filter can select tests from in place of the run's --testsuite
 * (MutationTestFiles), so the process loads a few files instead of every file of the suites, and
 * loads the mutated source first (MutatedSourcePreload).
 */
final class PestMutationReport implements Bootable, HandlesArguments
{
    public const string VARIABLE = 'CMS_MUTATION_REPORT';

    public function boot(): void
    {
        if (getenv(Mutate::ENV_MUTATION_TESTING) !== false || Parallel::isWorker()) {
            return;
        }

        Facade::instance()->registerSubscriber(CoverageFilterWidener::forPest());
        Facade::instance()->registerSubscriber(new CoverageOffForMutations);

        $skip = SkipCaughtMutations::fromEnvironment();

        if ($skip instanceof SkipCaughtMutations) {
            Facade::instance()->registerSubscriber($skip);
        }

        if (getenv(self::VARIABLE) === '1') {
            Facade::instance()->registerSubscriber(new MutationReportPrinter(STDOUT));
            $this->guardCheckout();
        }
    }

    /**
     * Removes, when the run ends, the files a mutation left in the checkout (CheckoutGuard).
     */
    private function guardCheckout(): void
    {
        $root = getcwd();
        $guard = $root === false ? null : CheckoutGuard::start($root);

        if (! $guard instanceof CheckoutGuard) {
            return;
        }

        register_shutdown_function(static function () use ($guard): void {
            foreach ($guard->removeStrays() as $stray) {
                fwrite(STDERR, "removed {$stray}: a mutation wrote it into the checkout\n");
            }
        });
    }

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public function handleArguments(array $arguments): array
    {
        $mutated = getenv(Mutate::ENV_MUTATION_TESTING);

        if ($mutated !== false) {
            MutatedSourcePreload::load($mutated);
        }

        $root = getcwd();

        if (getenv(Mutate::ENV_MUTATION_TESTING) === false || $root === false || ! is_file($root.'/phpunit.xml')) {
            return $arguments;
        }

        return MutationTestFiles::narrow(
            array_values($arguments),
            static fn (array $suites): array => SuiteTestFiles::contents($root, $suites),
        );
    }
}
