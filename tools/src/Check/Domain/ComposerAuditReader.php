<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * Reads the JSON report of `composer audit --format=json` for gate 9 (GUARDRAILS 10). Any
 * security advisory fails the step, also when the exit code says otherwise. An abandoned package
 * never fails it, as `--abandoned=report` asks, but each one is listed as a note with its
 * suggested replacement, so the report names it. Output without the JSON report fails the step,
 * because then nothing says the audit found no advisories.
 */
final readonly class ComposerAuditReader implements OutputReader
{
    public function read(ProcessOutcome $outcome): OutputReading
    {
        $report = json_decode($this->reportText($outcome->output), true);
        $advisories = is_array($report) ? ($report['advisories'] ?? null) : null;
        $abandoned = is_array($report) ? ($report['abandoned'] ?? null) : null;

        if (! is_array($advisories) || ! is_array($abandoned)) {
            return new OutputReading(failure: 'composer audit printed no JSON report with advisories and abandoned packages');
        }

        $notes = [];
        $advised = [];

        foreach ($advisories as $package => $packageAdvisories) {
            foreach (is_array($packageAdvisories) ? $packageAdvisories : [$packageAdvisories] as $advisory) {
                // The title, CVE and severity of the advisory, when Composer gave them.
                $details = [];

                foreach (['title', 'cve', 'severity'] as $key) {
                    $value = is_array($advisory) ? ($advisory[$key] ?? null) : null;

                    if (is_string($value) && trim($value) !== '') {
                        $details[] = trim(str_replace(["\r", "\n"], ' ', $value));
                    }
                }

                $advised[] = (string) $package;
                $notes[] = 'advisory: '.$package.($details === [] ? '' : ': '.implode(', ', $details));
            }
        }

        foreach ($abandoned as $package => $replacement) {
            $notes[] = 'abandoned: '.$package.(is_string($replacement) && $replacement !== ''
                ? ', replaced by '.$replacement
                : ', no replacement suggested');
        }

        $count = count($advised);

        return new OutputReading(
            $notes,
            $count === 0 ? null : sprintf(
                '%d security %s: %s',
                $count,
                $count === 1 ? 'advisory' : 'advisories',
                implode(', ', array_values(array_unique($advised))),
            ),
        );
    }

    /**
     * The JSON object Composer prints, from its first line `{` to its last line `}`. Composer
     * writes warnings to standard error, which the runner interleaves with standard output.
     */
    private function reportText(string $output): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $output));
        $first = array_search('{', $lines, true);
        $last = array_search('}', array_reverse($lines, true), true);

        if (! is_int($first) || ! is_int($last) || $last < $first) {
            return '';
        }

        return implode("\n", array_slice($lines, $first, $last - $first + 1));
    }
}
