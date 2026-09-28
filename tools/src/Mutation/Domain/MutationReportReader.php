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
 * is listed as such. The step fails when the score of all its mutations is below the minimum, naming the
 * sources below it, and when the report is missing, because then nothing says the mutations ran.
 *
 * The fast suites' step records its whole report in a MutationLedger, the sources in Adapter and
 * Infrastructure included, which it does not judge. The step with Postgres counts a mutation as
 * caught when its own run or the recorded one caught it, as one run of both suites would.
 */
final readonly class MutationReportReader implements OutputReader
{
    public const string MARKER = 'CMS-MUTATION-REPORT ';

    public const int FORMAT = 2;

    /**
     * @param  list<ChangedSource>  $sources  the sources the step judges; none only for a reader
     *                                        that records the report for another step
     * @param  MutationLedger|null  $records  where to record every file of the report
     * @param  MutationLedger|null  $counts  the recorded outcomes to count as caught as well
     */
    public function __construct(
        public array $sources,
        public int $minScore,
        public ?MutationLedger $records = null,
        public ?MutationLedger $counts = null,
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
            return new OutputReading(['recorded for the step with Postgres: every changed source is in Adapter or Infrastructure']);
        }

        $notes = [];
        $below = [];
        $mutations = 0;
        $caught = 0;
        $caughtBefore = 0;

        foreach ($this->sources as $source) {
            $outcomes = $this->outcomes($source->path, $files[$source->path] ?? []);
            $count = new MutationCount(count($outcomes), count(array_filter($outcomes)));
            $mutations += $count->mutations;
            $caught += $count->caught;
            $caughtBefore += count(array_filter($this->counts?->outcomes($source->path) ?? []));

            if ($count->mutations === 0) {
                $notes[] = "{$source->name}: no mutations";

                continue;
            }

            $notes[] = sprintf('%s: %s, %d of %d mutations caught', $source->name, $this->percent($count->score()), $count->caught, $count->mutations);

            if ($count->score() < $this->minScore) {
                $below[] = sprintf('%s %s', $source->name, $this->percent($count->score()));
            }
        }

        if ($mutations === 0) {
            return new OutputReading([...$notes, 'no mutations in the changed sources']);
        }

        if ($this->counts instanceof MutationLedger) {
            $notes[] = $this->counts->recorded()
                ? sprintf('counted with the fast suites\' run, which caught %d of them', $caughtBefore)
                : 'the fast suites\' run recorded no report, so only this run counts';
        }

        $score = new MutationCount($mutations, $caught)->score();
        $notes[] = sprintf('score %s of %d mutations, minimum %d%%', $this->percent($score), $mutations, $this->minScore);

        return new OutputReading($notes, $score >= $this->minScore ? null : sprintf(
            'mutation score %s is below %d%%%s',
            $this->percent($score),
            $this->minScore,
            $below === [] ? '' : '; below it: '.implode(', ', $below),
        ));
    }

    /**
     * Whether each mutation of a file was caught, by hash: in this run, or in the recorded run this
     * reader counts as well. A mutation only one of the runs made counts with that run's outcome.
     *
     * @param  list<MutationOutcome>  $outcomes  this run's outcomes of the file
     * @return array<string, bool>
     */
    private function outcomes(string $path, array $outcomes): array
    {
        $caught = $this->counts?->outcomes($path) ?? [];

        foreach ($outcomes as $outcome) {
            $caught[$outcome->hash] = $outcome->caught || ($caught[$outcome->hash] ?? false);
        }

        return $caught;
    }

    /**
     * Each file's mutations in the last report line, by path, or null when there is no report
     * line in the format.
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

                if (! is_string($id) || $id === '' || str_contains($id, "\n") || isset($outcomes[$id]) || ! is_bool($caught)) {
                    return null;
                }

                $outcomes[$id] = new MutationOutcome($id, $caught);
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
