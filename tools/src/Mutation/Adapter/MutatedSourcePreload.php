<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Adapter;

/**
 * Loads the mutated source in a process that tests one mutation, before any test runs.
 *
 * pest-plugin-mutate swaps the source for its mutation through a stream wrapper on `file://`,
 * which serves the mutated code to whatever includes the source's path. PHPStan's
 * FileReadTrapStreamWrapper, which a PHPStan analysis in a test registers and then restores,
 * puts PHP's own `file://` wrapper back, so a class that is first loaded after a test started an
 * analysis, such as a list of names a rule reads, was loaded from the original source, and its
 * mutations survived every test (M1-T66). Loading the source once while the swap is in place
 * gives the process the mutated class whatever the tests do afterwards.
 */
final readonly class MutatedSourcePreload
{
    /**
     * Includes the source at $path once, through the stream wrapper that serves its mutation.
     */
    public static function load(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            require_once $path;
        }
    }
}
