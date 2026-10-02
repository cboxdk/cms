<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Cbox\Cms\Tooling\Check\Domain\StepPrecheck;
use Cbox\Cms\Tooling\Check\Domain\StepPreparation;
use InvalidArgumentException;
use RuntimeException;

/**
 * The precheck of the step with Postgres: when the fast suites' run caught every mutation of the
 * changed sources, the Postgres suite cannot change their score, which
 * counts a mutation as caught when either run caught it, so the step passes without running it.
 * Without a recorded report, or with one mutation the fast suites did not catch, the step runs.
 * When it passes the step, it adds each source to the tally with the fast suites' count. A source
 * with an entry on the list of equivalent mutations always lets the step run, so the step's reader
 * judges whether the entry still names a surviving mutation in a run of every suite.
 *
 * When it runs, prepare() writes the mutations of the sources that the fast suites caught to
 * CAUGHT_FILE and names the file in VARIABLE, and the report plugin leaves them out of the
 * Postgres suite's run (SkipCaughtMutations): the step counts them as caught from the ledger
 * already, so running them again could not change the score. The Postgres suite then runs only the
 * mutations the fast suites did not catch.
 */
final readonly class CaughtByFastSuites implements StepPrecheck, StepPreparation
{
    /** The variable that names the file of caught mutations for the Pest run. */
    public const string VARIABLE = 'CMS_MUTATION_CAUGHT';

    /** Where prepare() writes the caught mutations, relative to the checked directory. */
    public const string CAUGHT_FILE = '.cache/mutation/caught.json';

    /**
     * @param  list<ChangedSource>  $sources  the sources of the step with Postgres
     * @param  MutationTally|null  $tally  where the sources' counts go when the step passes
     *                                     without running: the fast suites' counts
     * @param  EquivalentMutations|null  $equivalents  the list the step's reader judges, none when null
     */
    public function __construct(
        public array $sources,
        public MutationLedger $ledger,
        public ?MutationTally $tally = null,
        public ?EquivalentMutations $equivalents = null,
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
            if (($this->equivalents?->of($source->path) ?? []) !== []) {
                return null;
            }

            foreach ($this->ledger->outcomes($source->path) as $caught) {
                if (! $caught) {
                    return null;
                }

                $mutations++;
            }
        }

        foreach ($this->sources as $source) {
            $outcomes = count($this->ledger->outcomes($source->path));
            $this->tally?->add($source, new MutationCount($outcomes, $outcomes));
        }

        return $mutations === 0
            ? 'no mutations in the changed sources, as the fast suites\' run showed, so the Postgres suite is not run'
            : sprintf('the fast suites caught all %d mutations of the changed sources, so the Postgres suite cannot change the score and is not run', $mutations);
    }

    /**
     * The ids of the mutations the fast suites caught in the sources, by path, each list sorted.
     *
     * @return array<string, list<string>>
     */
    public function caught(): array
    {
        $caught = [];

        foreach ($this->sources as $source) {
            $ids = array_keys(array_filter($this->ledger->outcomes($source->path)));

            if ($ids !== []) {
                sort($ids, SORT_STRING);
                $caught[$source->path] = array_map(strval(...), $ids);
            }
        }

        return $caught;
    }

    /**
     * @return array<string, string>
     */
    public function prepare(string $directory): array
    {
        $file = rtrim($directory, '/').'/'.self::CAUGHT_FILE;
        $json = json_encode(['files' => (object) $this->caught()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0o777, true) && ! is_dir(dirname($file))) {
            throw new RuntimeException('Cannot create '.dirname($file).'.');
        }

        if (file_put_contents($file, $json) !== strlen($json)) {
            throw new RuntimeException("Cannot write {$file}.");
        }

        return [self::VARIABLE => self::CAUGHT_FILE];
    }
}
