<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Cbox\Cms\Tooling\Check\Domain\OutputReader;
use Cbox\Cms\Tooling\Check\Domain\OutputReading;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use InvalidArgumentException;

/**
 * Reads the mutation report that the Pest plugin PestMutationReport prints on one line after the
 * mutations ran: for each file with mutations, Pest's id of each mutation, a hash, and whether a
 * test caught it, as Pest's score counts it: the mutations that failed a test or timed out. Each
 * changed source the step judges is listed as a note with its score; a source without a mutation
 * is listed as such. The step fails when a source's score over all its mutations is below the
 * minimum, naming each such source, one verdict per class as the shards' verdict gives it
 * (MutationVerdict), and when the report is missing, because then nothing says the mutations ran.
 * The score of all the step's mutations is listed as a note. Each judged source's count goes to
 * the tally, for the shard's report.
 *
 * The fast suites' step records its whole report in a MutationLedger and judges nothing. The step
 * with Postgres counts a mutation as caught when its own run or the recorded one caught it, as one
 * run of both suites would.
 *
 * A mutation on the list of equivalent mutations is not applicable: it is left out of its source's
 * count and never counted as caught (EquivalentMutations). An entry for a judged source that names
 * no mutation of the run, or one a test caught, fails the step as stale.
 */
final readonly class MutationReportReader implements OutputReader
{
    public const string MARKER = 'CMS-MUTATION-REPORT ';

    public const int FORMAT = 3;

    /**
     * @param  list<ChangedSource>  $sources  the sources the step judges; none only for a reader
     *                                        that records the report for another step
     * @param  MutationLedger|null  $records  where to record every file of the report
     * @param  MutationLedger|null  $counts  the recorded outcomes to count as caught as well
     * @param  MutationTally|null  $tally  where to add the count of each judged source
     * @param  EquivalentMutations|null  $equivalents  the mutations to leave out of the count of
     *                                                 each judged source, none when null
     */
    public function __construct(
        public array $sources,
        public int $minScore,
        public ?MutationLedger $records = null,
        public ?MutationLedger $counts = null,
        public ?MutationTally $tally = null,
        public ?EquivalentMutations $equivalents = null,
    ) {
        if ($sources === [] && ! $records instanceof MutationLedger) {
            throw new InvalidArgumentException('A mutation report is read for at least one changed source, or recorded for another step.');
        }

        if ($minScore < 0 || $minScore > 100) {
            throw new InvalidArgumentException("A minimum score is a percentage, not {$minScore}.");
        }
    }

    public function read(ProcessOutcome $outcome): OutputReading
    {
        $files = $this->files($outcome->output);

        if ($files === null) {
            return new OutputReading(failure: sprintf(
                'Pest printed no mutation report, so no mutation was checked: %s',
                $outcome->exitCode === 0 ? 'the plugin in composer.json\'s extra.pest.plugins did not run' : 'a test failed before the mutations ran, or Pest stopped',
            ));
        }

        $this->records?->record($files);

        if ($this->sources === []) {
            return new OutputReading(['recorded for the step with Postgres, which judges every changed source over both runs']);
        }

        $notes = [];
        $below = [];
        $stale = [];
        $mutations = 0;
        $caught = 0;
        $caughtBefore = 0;

        foreach ($this->sources as $source) {
            $outcomes = $this->outcomes($source->path, $files[$source->path] ?? []);
            $judgement = ($this->equivalents ?? new EquivalentMutations([]))->judge($source->path, $outcomes);
            $count = $judgement->count;
            $stale = [...$stale, ...$judgement->stale];
            $this->tally?->add($source, $count);
            $mutations += $count->mutations;
            $caught += $count->caught;
            $caughtBefore += count(array_filter($this->counts?->outcomes($source->path) ?? []));

            $equivalent = $count->equivalent === 0 ? '' : sprintf(', %d equivalent left out', $count->equivalent);

            if ($count->mutations === 0) {
                $notes[] = "{$source->name}: no mutations{$equivalent}";

                continue;
            }

            $notes[] = sprintf('%s: %s, %d of %d mutations caught%s', $source->name, $this->percent($count->score()), $count->caught, $count->mutations, $equivalent);

            if ($count->score() < $this->minScore) {
                $below[] = sprintf('%s %s', $source->name, $this->percent($count->score()));
            }
        }

        $staleFailure = $stale === [] ? null : 'stale equivalent mutations: '.implode('; ', $stale);

        if ($mutations === 0) {
            return new OutputReading([...$notes, 'no mutations in the changed sources'], $staleFailure);
        }

        if ($this->counts instanceof MutationLedger) {
            $notes[] = $this->counts->recorded()
                ? sprintf('counted with the fast suites\' run, which caught %d of them', $caughtBefore)
                : 'the fast suites\' run recorded no report, so only this run counts';
        }

        $score = new MutationCount($mutations, $caught)->score();
        $notes[] = sprintf('score %s of %d mutations, minimum %d%% for each class', $this->percent($score), $mutations, $this->minScore);

        $failures = $below === [] ? [] : [sprintf('below %d%% over its mutations: %s', $this->minScore, implode(', ', $below))];

        if ($staleFailure !== null) {
            $failures[] = $staleFailure;
        }

        return new OutputReading($notes, $failures === [] ? null : implode('; and ', $failures));
    }

    /**
     * Each mutation of a file, once: caught when this run or the recorded run this reader counts as
     * well caught it. A mutation only one of the runs made counts with that run's outcome.
     *
     * @param  list<MutationOutcome>  $outcomes  this run's outcomes of the file
     * @return list<MutationOutcome>
     */
    private function outcomes(string $path, array $outcomes): array
    {
        $merged = $this->counts?->mutations($path) ?? [];

        foreach ($outcomes as $outcome) {
            $merged[$outcome->hash] = new MutationOutcome(
                $outcome->hash,
                $outcome->caught || ($merged[$outcome->hash]->caught ?? false),
                $outcome->line,
                $outcome->mutator,
            );
        }

        return array_values($merged);
    }

    /**
     * Each file's mutations in the last report line, by path, or null when there is no report
     * line in the format: each mutation with its id, whether it was caught, the line it starts on
     * and its mutator. Pest can make two mutations with the same id in one file, when two
     * mutations give the same source, such as removing either element of `[$a, $a]`; they are the
     * same mutation, so one listed twice with the same outcome counts once. The same id with two
     * outcomes is no report in the format, because then the report cannot say whether it was caught.
     *
     * @return array<string, list<MutationOutcome>>|null
     */
    private function files(string $output): ?array
    {
        $report = null;

        foreach (explode("\n", str_replace("\r\n", "\n", $output)) as $line) {
            if (str_starts_with($line, self::MARKER)) {
                $report = json_decode(substr($line, strlen(self::MARKER)), true);
            }
        }

        if (! is_array($report) || ($report['format'] ?? null) !== self::FORMAT || ! is_array($report['files'] ?? null)) {
            return null;
        }

        $files = [];

        foreach ($report['files'] as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            $mutations = is_array($file) ? ($file['mutations'] ?? null) : null;

            if (! is_string($path) || $path === '' || isset($files[$path]) || ! is_array($mutations) || ! array_is_list($mutations)) {
                return null;
            }

            $outcomes = [];

            foreach ($mutations as $mutation) {
                $id = is_array($mutation) ? ($mutation['id'] ?? null) : null;
                $caught = is_array($mutation) ? ($mutation['caught'] ?? null) : null;
                $line = is_array($mutation) ? ($mutation['line'] ?? null) : null;
                $mutator = is_array($mutation) ? ($mutation['mutator'] ?? null) : null;

                if (! is_string($id) || $id === '' || str_contains($id, "\n") || ! is_bool($caught)
                    || ! is_int($line) || $line < 1 || ! is_string($mutator) || $mutator === '' || str_contains($mutator, "\n")) {
                    return null;
                }

                if (isset($outcomes[$id])) {
                    if ($outcomes[$id]->caught !== $caught) {
                        return null;
                    }

                    continue;
                }

                $outcomes[$id] = new MutationOutcome($id, $caught, $line, $mutator);
            }

            $files[$path] = array_values($outcomes);
        }

        return $files;
    }

    private function percent(float $score): string
    {
        return number_format($score, 2).'%';
    }
}
