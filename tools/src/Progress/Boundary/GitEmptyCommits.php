<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Boundary;

use Cbox\Cms\Tooling\Progress\Domain\EmptyCommit;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * The commits of a revision range in a git checkout that change no file, oldest first, read from
 * `git log --reverse --no-merges --name-only`. A merge commit is left out, because the merge queue
 * only fast-forwards. A range git cannot read throws with git's message.
 */
final readonly class GitEmptyCommits
{
    private const string RECORD = "\x1e";

    private const string FIELD = "\x1f";

    /**
     * @param  string  $root  the root of the checkout
     * @param  string  $range  a revision range, such as main..HEAD
     * @return list<EmptyCommit>
     */
    public static function in(string $root, string $range): array
    {
        if ($range === '' || str_starts_with($range, '-')) {
            throw new UnexpectedValueException("A revision range such as main..HEAD, not [{$range}].");
        }

        $process = new Process(
            ['git', 'log', '--reverse', '--no-merges', '--no-renames', '--name-only', '--format='.self::RECORD.'%H'.self::FIELD.'%s', '--end-of-options', $range, '--'],
            $root,
            ['GIT_OPTIONAL_LOCKS' => '0'],
            null,
            60,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim(preg_replace('/\s+/', ' ', $process->getErrorOutput()) ?? '');

            throw new UnexpectedValueException("git log cannot read the range {$range} in {$root}: ".($message === '' ? 'git exited '.($process->getExitCode() ?? 'without an exit code').'.' : $message));
        }

        $empty = [];

        foreach (explode(self::RECORD, $process->getOutput()) as $record) {
            $lines = array_values(array_filter(explode("\n", $record), static fn (string $line): bool => trim($line) !== ''));

            if ($lines === []) {
                continue;
            }

            [$commit, $subject] = array_pad(explode(self::FIELD, $lines[0], 2), 2, '');

            if (count($lines) === 1) {
                $empty[] = new EmptyCommit($commit, $subject);
            }
        }

        return $empty;
    }
}
