<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Domain;

use Cbox\Cms\Tooling\Check\Domain\OutputReader;
use Cbox\Cms\Tooling\Check\Domain\OutputReading;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use InvalidArgumentException;

/**
 * Reads the mutation report that the Pest plugin PestMutationReport prints on one line after the
 * mutations ran: for each file with mutations, how many there were and how many a test caught.
 * Each changed source of the step is listed as a note with its score, as Pest computes it: the
 * caught mutations, those that failed a test or timed out, out of all. A source without a mutation
 * is listed as such. The step fails when the score of all its mutations is below the minimum,
 * naming the sources below it, and when the report is missing, because then nothing says the
 * mutations ran.
 */
final readonly class MutationReportReader implements OutputReader
{
    public const string MARKER = 'CMS-MUTATION-REPORT ';

    public const int FORMAT = 1;

    /**
     * @param  list<ChangedSource>  $sources
     */
    public function __construct(
        public array $sources,
        public int $minScore,
    ) {
        if ($sources === []) {
            throw new InvalidArgumentException('A mutation report is read for at least one changed source.');
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

        $notes = [];
        $below = [];
        $mutations = 0;
        $caught = 0;

        foreach ($this->sources as $source) {
            $count = $files[$source->path] ?? new MutationCount(0, 0);
            $mutations += $count->mutations;
            $caught += $count->caught;

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
     * The mutations and caught mutations of each file in the last report line, by path, or null
     * when there is no report line in the format.
     *
     * @return array<string, MutationCount>|null
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
            $caught = is_array($file) ? ($file['caught'] ?? null) : null;

            if (! is_string($path) || ! is_int($mutations) || ! is_int($caught) || $caught < 0 || $caught > $mutations) {
                return null;
            }

            $files[$path] = new MutationCount($mutations, $caught);
        }

        return $files;
    }

    private function percent(float $score): string
    {
        return number_format($score, 2).'%';
    }
}
