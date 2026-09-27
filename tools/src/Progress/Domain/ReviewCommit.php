<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

/**
 * A commit made straight on main for a review finding, such as `M0-review: ...`, outside the merge
 * queue and so outside `composer progress:check`: its full hash, the first line of its message,
 * the check files it changed or removed (CheckPaths) and the entries it added to PROGRESS.md under
 * "Til review af Sylvester" and "Kontroller kørt".
 */
final readonly class ReviewCommit
{
    /** The first line of a review commit's message: the block, `-review:` and what it fixes. */
    public const string SUBJECT = '/^([A-Z][A-Za-z0-9]*)-review:/';

    /**
     * @param  list<string>  $changedChecks  the check files the commit changed or removed, by path
     * @param  list<string>  $addedReview  the entries the commit added under "Til review af Sylvester"
     * @param  list<string>  $addedChecksRun  the entries the commit added under "Kontroller kørt"
     */
    public function __construct(
        public string $commit,
        public string $subject,
        public array $changedChecks,
        public array $addedReview,
        public array $addedChecksRun,
    ) {}

    /**
     * Whether the first line of a commit message is that of a review commit.
     */
    public static function isReviewSubject(string $subject): bool
    {
        return preg_match(self::SUBJECT, $subject) === 1;
    }

    /**
     * The review label the commit's entries name, such as M0-review.
     */
    public function label(): TaskId
    {
        preg_match(self::SUBJECT, $this->subject, $match);

        return new TaskId(($match[1] ?? '').'-review');
    }

    /**
     * Whether a text names this commit by its hash, abbreviated to at least 7 hex digits.
     */
    public function namedBy(string $text): bool
    {
        preg_match_all('/(?<![0-9a-f])[0-9a-f]{7,40}(?![0-9a-f])/', $text, $matches);

        return array_any($matches[0], fn (string $abbreviation): bool => str_starts_with($this->commit, $abbreviation));
    }
}
