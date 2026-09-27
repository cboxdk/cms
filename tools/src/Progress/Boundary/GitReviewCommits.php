<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Boundary;

use Cbox\Cms\Tooling\Progress\Domain\CheckPaths;
use Cbox\Cms\Tooling\Progress\Domain\ProgressLedger;
use Cbox\Cms\Tooling\Progress\Domain\ReviewCommit;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * The review commits reachable from a revision of a git checkout, oldest first: every commit, not
 * a merge, whose first line is that of a review commit (ReviewCommit::SUBJECT), with the check files
 * it changed or removed, read from `git log --numstat --no-renames` (a file with deleted lines, so
 * a rename counts as removing the old path), and the entries it added to PROGRESS.md, from the file
 * at the commit and at its first parent through `git cat-file --batch`. A revision git cannot
 * read throws with git's message.
 */
final readonly class GitReviewCommits
{
    private const string RECORD = "\x1e";

    private const string FIELD = "\x1f";

    /**
     * @param  string  $root  the root of the checkout
     * @param  string  $revision  the revision whose history is read, such as HEAD
     * @return list<ReviewCommit>
     */
    public static function in(string $root, string $revision): array
    {
        if ($revision === '' || str_starts_with($revision, '-')) {
            throw new UnexpectedValueException("A revision such as HEAD, not [{$revision}].");
        }

        $log = self::git($root, ['log', '--reverse', '--no-merges', '--no-renames', '--numstat', '--format='.self::RECORD.'%H'.self::FIELD.'%s', '--end-of-options', $revision, '--'], null, "git log cannot read {$revision}");
        $found = [];

        foreach (explode(self::RECORD, $log) as $record) {
            $lines = array_values(array_filter(explode("\n", $record), static fn (string $line): bool => trim($line) !== ''));

            if ($lines === []) {
                continue;
            }

            [$commit, $subject] = array_pad(explode(self::FIELD, $lines[0], 2), 2, '');

            if (! ReviewCommit::isReviewSubject($subject)) {
                continue;
            }

            $changed = [];

            foreach (array_slice($lines, 1) as $line) {
                $fields = explode("\t", $line, 3);

                if (count($fields) === 3 && $fields[1] !== '0' && CheckPaths::isCheck($fields[2])) {
                    $changed[] = $fields[2];
                }
            }

            $found[] = [$commit, $subject, $changed];
        }

        if ($found === []) {
            return [];
        }

        $request = '';

        foreach ($found as [$commit]) {
            $request .= "{$commit}:PROGRESS.md\n{$commit}^:PROGRESS.md\n";
        }

        $blobs = self::blobs(self::git($root, ['cat-file', '--batch'], $request, 'git cat-file cannot read PROGRESS.md'), count($found) * 2);
        $commits = [];

        foreach ($found as $index => [$commit, $subject, $changed]) {
            $after = ProgressLedger::fromMarkdown($blobs[$index * 2]);
            $before = ProgressLedger::fromMarkdown($blobs[$index * 2 + 1]);

            $commits[] = new ReviewCommit(
                $commit,
                $subject,
                $changed,
                $after->addedSince($before, ProgressLedger::REVIEW),
                $after->addedSince($before, ProgressLedger::CHECKS_RUN),
            );
        }

        return $commits;
    }

    /**
     * The contents of each object `git cat-file --batch` printed, in order; an empty string for
     * one it reported missing, such as PROGRESS.md before the file existed or the parent of a root
     * commit.
     *
     * @return list<string>
     */
    private static function blobs(string $output, int $count): array
    {
        $blobs = [];
        $offset = 0;

        while (count($blobs) < $count) {
            $end = strpos($output, "\n", $offset);

            if ($end === false) {
                throw new UnexpectedValueException('git cat-file printed fewer objects than it was asked for.');
            }

            $header = substr($output, $offset, $end - $offset);
            $offset = $end + 1;

            if (preg_match('/^[0-9a-f]+ blob (\d+)$/', $header, $match) === 1) {
                $blobs[] = substr($output, $offset, (int) $match[1]);
                $offset += (int) $match[1] + 1;
            } elseif (str_ends_with($header, ' missing')) {
                $blobs[] = '';
            } else {
                throw new UnexpectedValueException("git cat-file printed [{$header}] where it names a blob or says missing.");
            }
        }

        return $blobs;
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function git(string $root, array $arguments, ?string $input, string $failure): string
    {
        $process = new Process(['git', ...$arguments], $root, ['GIT_OPTIONAL_LOCKS' => '0'], $input, 60);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? '');

            throw new UnexpectedValueException("{$failure} in {$root}: ".($message === '' ? 'git exited '.($process->getExitCode() ?? 'without an exit code').'.' : $message));
        }

        return $process->getOutput();
    }
}
