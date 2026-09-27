<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * What a review commit made straight on main is held to, as `composer progress:check` holds a task
 * of the merge queue (GUARDRAILS 7.3 and 11). The commit adds an entry naming its label, such as
 * M0-review, under "Kontroller kørt" in PROGRESS.md with the gates it ran; and when it changed or
 * removed a check file (CheckPaths), a record naming its label that says GUARDRAILS 7.3, with each
 * changed or removed test expectation and why (ReviewCommit::$addedRecords: in CHECKS-LOG.md under
 * its block's heading, or for a commit made before the log existed, under "Til review af
 * Sylvester"). A commit that did not can be recorded afterwards by an entry that names its hash:
 * under "Kontroller kørt" for the gate runs, and in CHECKS-LOG.md under its block for the record.
 */
final readonly class ReviewCommitAudit
{
    /**
     * The problems of each commit, in the order given; none when every commit is recorded.
     *
     * @param  list<ReviewCommit>  $commits
     * @return list<string>
     */
    public static function problems(ProgressLedger $ledger, ChecksLog $log, array $commits): array
    {
        $problems = [];

        foreach ($commits as $commit) {
            $label = $commit->label();
            $short = substr($commit->commit, 0, 7);
            $subject = mb_strlen($commit->subject) > 72 ? mb_substr($commit->subject, 0, 71).'…' : $commit->subject;

            $checksRun = array_any($commit->addedChecksRun, static fn (string $entry): bool => $label->namedIn($entry))
                || array_any($ledger->entries(ProgressLedger::CHECKS_RUN), static fn (string $entry): bool => $commit->namedBy($entry));

            if (! $checksRun) {
                $problems[] = sprintf(
                    'The review commit %s "%s" added no entry for %s under "## %s", and no entry there names %s. Add one that names %s, with the gates that ran on it and their results.',
                    $short,
                    $subject,
                    $label->value,
                    ProgressLedger::CHECKS_RUN,
                    $short,
                    $short,
                );
            }

            if ($commit->changedChecks === []) {
                continue;
            }

            $recorded = array_any($commit->addedRecords, static fn (string $entry): bool => $label->namedIn($entry) && ChecksLog::isRecord($entry))
                || array_any($log->entries($label->block()), static fn (string $entry): bool => $commit->namedBy($entry) && ChecksLog::isRecord($entry));

            if (! $recorded) {
                $problems[] = sprintf(
                    'The review commit %s "%s" changed or removed checks (%s) but added no entry for %s to %s under "## %s" that says %s, and no such entry there names %s. Add one that names %s and each changed or removed test expectation, suite, tool configuration or CI file, and why.',
                    $short,
                    $subject,
                    implode(', ', $commit->changedChecks),
                    $label->value,
                    ChecksLog::FILE,
                    $label->block(),
                    ChecksLog::RULE,
                    $short,
                    $short,
                );
            }
        }

        return $problems;
    }
}
