<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;

/**
 * The time the hooks of one command have taken so far (PRD 6.3): each hook has its own budget, at
 * most 20 ms, and all of them together have 100 ms. A hook's time is charged when it returns; PHP
 * cannot stop a hook while it runs, so the budget decides whether the command goes on, not how
 * long the hook may run.
 */
#[Internal]
final readonly class HookBudget
{
    public const int COMMAND_NANOSECONDS = Hook::COMMAND_BUDGET_MS * 1_000_000;

    public function __construct(public int $spentNanoseconds = 0) {}

    /**
     * The budget after the hook, with the time it took charged, or the overrun when it went over
     * its own budget or took the command's hooks over theirs. A hook's own budget is checked
     * first.
     */
    public function charge(BoundHook $hook, int $elapsedNanoseconds, CommandName $command, int $version): self|HookOverrun
    {
        $elapsed = max(0, $elapsedNanoseconds);
        $spent = $this->spentNanoseconds + $elapsed;

        $kind = match (true) {
            $elapsed > $hook->budgetNanoseconds() => OverrunKind::Hook,
            $spent > self::COMMAND_NANOSECONDS => OverrunKind::Command,
            default => null,
        };

        return $kind === null
            ? new self($spent)
            : new HookOverrun($command, $version, $hook->class, $hook->package, $hook->phase, $kind, $hook->budgetMs, $elapsed, $spent);
    }
}
