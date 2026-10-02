<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

use Cbox\Cms\Tooling\Mutation\Domain\MutationSteps;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuite;
use Pest\Mutate\Event\Events\TestSuite\StartMutationSuiteSubscriber;

/**
 * Turns PCOV off for the Pest processes that test one mutation each.
 *
 * MutationSteps enables PCOV for its Pest run through PHP_INI_SCAN_DIR, because the run and its
 * parallel workers collect the coverage that decides which tests each mutation runs. The processes
 * that test the mutations collect none, but they inherit the variable, and PCOV enabled made each
 * of them about half again as slow. pest-plugin-mutate emits StartMutationSuite after the coverage
 * is read and the mutations are made, before it starts those processes; this subscriber then
 * removes the variable from the environment they inherit, when it holds what MutationSteps set,
 * so they read the image's ini files alone.
 */
final readonly class CoverageOffForMutations implements StartMutationSuiteSubscriber
{
    public const string VARIABLE = 'PHP_INI_SCAN_DIR';

    public function notify(StartMutationSuite $event): void
    {
        self::turnOff();
    }

    /**
     * Removes the variable when MutationSteps set it, and says whether it did.
     */
    public static function turnOff(): bool
    {
        if (getenv(self::VARIABLE) !== MutationSteps::ENVIRONMENT[self::VARIABLE]) {
            return false;
        }

        putenv(self::VARIABLE);
        unset($_ENV[self::VARIABLE], $_SERVER[self::VARIABLE]);

        return true;
    }
}
