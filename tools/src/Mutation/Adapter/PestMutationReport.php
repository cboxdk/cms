<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Pest\Contracts\Plugins\Bootable;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Plugins\Mutate;
use Pest\Plugins\Parallel;

/**
 * A Pest plugin, listed in composer.json's extra.pest.plugins, that lets mutation on changed
 * files (Cbox\Cms\Tooling\Mutation\Domain\MutationSteps) read the score of each changed file.
 * Pest prints only the score of the whole run, and with --parallel not even which file a mutation
 * belongs to. With CMS_MUTATION_REPORT=1 in the environment, the Pest process that runs the
 * mutations prints MutationReportPrinter's report line when they are done. It does nothing in
 * the processes that test one mutation or run a worker's tests, and nothing without the variable.
 */
final class PestMutationReport implements Bootable
{
    public const string VARIABLE = 'CMS_MUTATION_REPORT';

    public function boot(): void
    {
        if (getenv(self::VARIABLE) !== '1' || getenv(Mutate::ENV_MUTATION_TESTING) !== false || Parallel::isWorker()) {
            return;
        }

        Facade::instance()->registerSubscriber(new MutationReportPrinter(STDOUT));
    }
}
