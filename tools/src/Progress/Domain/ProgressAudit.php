<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * What `composer progress:check` holds a task to before the merge queue moves main (CLAUDE.md,
 * GUARDRAILS 7.3 and 11): PROGRESS.md names the task under "Kontroller kørt", with the gates the
 * task and the merge queue ran; when the task changed or removed a check, CHECKS-LOG.md names the
 * task under its block's heading in an entry that says GUARDRAILS 7.3, so the review of changed
 * checks (gate 11) has something to review; and no commit of the task changes nothing, because an
 * empty commit is how a task hands work to a later step that never does it. An entry under "Til
 * review af Sylvester" in PROGRESS.md is no record: that section holds only open decisions.
 */
final readonly class ProgressAudit
{
    /**
     * The problems, in the order above; none when the task is recorded.
     *
     * @param  list<EmptyCommit>  $emptyCommits  the task's commits that change no file
     * @return list<string>
     */
    public static function problems(ProgressLedger $ledger, ChecksLog $log, TaskId $task, bool $changedChecks, array $emptyCommits): array
    {
        $problems = [];

        if (! array_any($ledger->entries(ProgressLedger::CHECKS_RUN), $task->namedIn(...))) {
            $problems[] = sprintf(
                'PROGRESS.md has no entry for %s under "## %s". Add one with the gates that ran on the task and their results: composer check, and composer check:selftest and the containerized CI run with its wall time when they ran.',
                $task->value,
                ProgressLedger::CHECKS_RUN,
            );
        }

        if ($changedChecks && ! $log->records($task)) {
            $problems[] = sprintf(
                '%s has no entry for %s under "## %s" that says %s, although the task changed or removed checks. Add one that names each changed or removed test expectation, suite, tool configuration or CI file and why; "## %s" in PROGRESS.md holds only open decisions.',
                ChecksLog::FILE,
                $task->value,
                $task->block(),
                ChecksLog::RULE,
                ProgressLedger::REVIEW,
            );
        }

        foreach ($emptyCommits as $empty) {
            $problems[] = sprintf(
                'The commit %s "%s" changes no file. Record what it was meant to hand on in PROGRESS.md in a commit that changes it, and drop the empty commit.',
                substr($empty->commit, 0, 12),
                $empty->subject,
            );
        }

        return $problems;
    }
}
