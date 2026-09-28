<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Cbox\Cms\Tooling\Check\Domain\StepPrecheck;
use InvalidArgumentException;

/**
 * The precheck of the step with Postgres: when the fast suites' run caught every mutation of the
 * sources in Adapter and Infrastructure, the Postgres suite cannot change their score, which
 * counts a mutation as caught when either run caught it, so the step passes without running it.
 * Without a recorded report, or with one mutation the fast suites did not catch, the step runs.
 */
final readonly class CaughtByFastSuites implements StepPrecheck
{
    /**
     * @param  list<ChangedSource>  $sources  the sources of the step with Postgres
     */
    public function __construct(
        public array $sources,
        public MutationLedger $ledger,
    ) {
        if ($sources === []) {
            throw new InvalidArgumentException('The precheck of the step with Postgres needs its sources.');
        }
    }

    public function passedWithout(): ?string
    {
        if (! $this->ledger->recorded()) {
            return null;
        }

        $mutations = 0;

        foreach ($this->sources as $source) {
            foreach ($this->ledger->outcomes($source->path) as $caught) {
                if (! $caught) {
                    return null;
                }

                $mutations++;
            }
        }

        return $mutations === 0
            ? 'no mutations in the changed sources, as the fast suites\' run showed, so the Postgres suite is not run'
            : sprintf('the fast suites caught all %d mutations of the changed sources, so the Postgres suite cannot change the score and is not run', $mutations);
    }
}
