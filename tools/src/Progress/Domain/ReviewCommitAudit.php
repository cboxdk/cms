<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * What a review commit made straight on main is held to, as `composer progress:check` holds a task
 * of the merge queue (GUARDRAILS 7.3 and 11). The commit adds an entry naming its label, such as
 * M0-review, under "Kontroller kørt" with the gates it ran; and when it changed or removed a check
 * file (CheckPaths), an entry naming its label under "Til review af Sylvester" that says
 * GUARDRAILS 7.3, with each changed or removed test expectation and why. A commit that did not
 * can be recorded afterwards by an entry in the same section that names its hash.
 */
final readonly class ReviewCommitAudit
{
    /**
     * The problems of each commit, in the order given; none when every commit is recorded.
     *
     * @param  list<ReviewCommit>  $commits
     * @return list<string>
     */
    public static function problems(ProgressLedger $ledger, array $commits): array
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

            $review = array_any($commit->addedReview, static fn (string $entry): bool => $label->namedIn($entry) && str_contains($entry, 'GUARDRAILS 7.3'))
                || array_any($ledger->entries(ProgressLedger::REVIEW), static fn (string $entry): bool => $commit->namedBy($entry) && str_contains($entry, 'GUARDRAILS 7.3'));

            if (! $review) {
                $problems[] = sprintf(
                    'The review commit %s "%s" changed or removed checks (%s) but added no entry for %s under "## %s" that says GUARDRAILS 7.3, and no such entry there names %s. Add one that names %s and each changed or removed test expectation, suite, tool configuration or CI file, and why.',
                    $short,
                    $subject,
                    implode(', ', $commit->changedChecks),
                    $label->value,
                    ProgressLedger::REVIEW,
                    $short,
                    $short,
                );
            }
        }

        return $problems;
    }
}
