<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use InvalidArgumentException;

/**
 * What the fast suites' mutation run caught, mutation by mutation, kept for the step with Postgres
 * in the same `composer check` process. That run mutates every changed source, so it holds the
 * fast suites' outcome for the sources in Adapter and Infrastructure as well, and the step with
 * Postgres counts a mutation as caught when either run caught it: the same as one run of both, in
 * which a mutation is caught when any test that covers it fails.
 *
 * It is empty until MutationReportReader records a report, and stays empty when the fast suites'
 * run printed none, so nothing is counted that did not run. A file that the recorded report does
 * not list had no mutations: the run named it in --path.
 */
final class MutationLedger
{
    /** @var array<string, array<string, MutationOutcome>>|null the outcomes by mutation hash, by path */
    private ?array $files = null;

    /**
     * Keeps the outcomes of one report. A second report replaces the first.
     *
     * @param  array<string, list<MutationOutcome>>  $files  the outcomes, by path relative to the checkout
     */
    public function record(array $files): void
    {
        $recorded = [];

        foreach ($files as $path => $outcomes) {
            foreach ($outcomes as $outcome) {
                if (isset($recorded[$path][$outcome->hash])) {
                    throw new InvalidArgumentException("The mutation {$outcome->hash} of {$path} is recorded twice.");
                }

                $recorded[$path][$outcome->hash] = $outcome;
            }
        }

        $this->files = $recorded;
    }

    /**
     * Whether a report of the fast suites' run was recorded.
     */
    public function recorded(): bool
    {
        return $this->files !== null;
    }

    /**
     * The recorded outcomes of a file, by mutation hash; empty when nothing was recorded or the file
     * had no mutations.
     *
     * @return array<string, bool>
     */
    public function outcomes(string $path): array
    {
        return array_map(static fn (MutationOutcome $outcome): bool => $outcome->caught, $this->mutations($path));
    }

    /**
     * The recorded mutations of a file, by hash; empty when nothing was recorded or the file had
     * no mutations.
     *
     * @return array<string, MutationOutcome>
     */
    public function mutations(string $path): array
    {
        return $this->files[$path] ?? [];
    }
}
