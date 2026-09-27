<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Progress\Domain;

use InvalidArgumentException;

/**
 * A task as PROGRESS.md and the commit messages name it: the block, a hyphen and the task, such as
 * M0-T43, M0-T41a or M1-R1-2. A text names the task when the id stands on its own there, so M0-T4
 * is not named by M0-T43 and M0-T41 is not named by M0-T41a.
 */
final readonly class TaskId
{
    private const string PATTERN = '/^[A-Z][A-Za-z0-9]*-[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("A task is named <block>-<task>, such as M0-T43, not [{$value}].");
        }
    }

    /**
     * The block the task belongs to, such as M0 for M0-T43 and M0-review.
     */
    public function block(): string
    {
        return strstr($this->value, '-', true) ?: $this->value;
    }

    public function namedIn(string $text): bool
    {
        return preg_match('/(?<![A-Za-z0-9-])'.preg_quote($this->value, '/').'(?![A-Za-z0-9])/', $text) === 1;
    }
}
