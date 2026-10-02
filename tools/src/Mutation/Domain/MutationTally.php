<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

/**
 * The count of each changed source the steps of one `composer check` judged, kept for the shard's
 * report (MutationShardReport): the fast suites' reader adds the sources outside Adapter and
 * Infrastructure, and the step with Postgres those in them, counted over both runs, or, when the
 * fast suites caught all their mutations and the step passed without running, as the fast suites
 * counted them (CaughtByFastSuites). A source no step counted, because its run printed no report,
 * is missing, and the verdict fails it.
 */
final class MutationTally
{
    /** @var array<string, ClassTally> by path */
    private array $classes = [];

    /**
     * Keeps the count of a source; a later count of the same source replaces the earlier one.
     */
    public function add(ChangedSource $source, MutationCount $count): void
    {
        $this->classes[$source->path] = new ClassTally($source->path, $source->name, $count);
    }

    /**
     * @return list<ClassTally> sorted by path
     */
    public function classes(): array
    {
        $classes = $this->classes;
        ksort($classes, SORT_STRING);

        return array_values($classes);
    }
}
