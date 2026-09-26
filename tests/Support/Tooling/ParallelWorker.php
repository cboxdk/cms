<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

/**
 * The variables a parallel Pest run sets for its workers, and the mutation step for the runs of
 * single mutations. A test that starts a Pest run of its own clears them, or that run takes itself
 * for a worker: the browser plugin, for one, then reads the Playwright server of a parent that
 * started none.
 */
final readonly class ParallelWorker
{
    public const array VARIABLES = ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'LARAVEL_PARALLEL_TESTING'];

    /**
     * The environment for a Symfony Process that removes the variables.
     *
     * @return array<string, false>
     */
    public static function cleared(): array
    {
        return array_fill_keys(self::VARIABLES, false);
    }

    /**
     * The arguments of env(1) that remove the variables.
     *
     * @return list<string>
     */
    public static function unsetArguments(): array
    {
        $arguments = [];

        foreach (self::VARIABLES as $variable) {
            $arguments[] = '-u';
            $arguments[] = $variable;
        }

        return $arguments;
    }
}
