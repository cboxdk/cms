<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Boundary;

use Cbox\Cms\Tooling\Progress\Domain\TaskId;
use InvalidArgumentException;

/**
 * The arguments of `composer progress:check -- <task> [--changed-checks] [--range=<range>]`: the
 * task, whether it changed or removed checks, and the revision range of its commits, if given.
 */
final readonly class ProgressCheckOptions
{
    public const string USAGE = 'Usage: php tools/bin/progress-check.php <block>-<task> [--changed-checks] [--range=<revision range>]';

    private function __construct(
        public TaskId $task,
        public bool $changedChecks,
        public ?string $range,
    ) {}

    /**
     * @param  list<string>  $arguments
     */
    public static function parse(array $arguments): self
    {
        $task = null;
        $changedChecks = false;
        $range = null;

        foreach ($arguments as $argument) {
            if ($argument === '--changed-checks') {
                $changedChecks = true;
            } elseif (str_starts_with($argument, '--range=') && strlen($argument) > strlen('--range=') && $range === null) {
                $range = substr($argument, strlen('--range='));
            } elseif (! str_starts_with($argument, '-') && ! $task instanceof TaskId) {
                $task = new TaskId($argument);
            } else {
                throw new InvalidArgumentException("Unknown or repeated argument [{$argument}].");
            }
        }

        if (! $task instanceof TaskId) {
            throw new InvalidArgumentException('Name the task, such as M0-T43.');
        }

        return new self($task, $changedChecks, $range);
    }
}
