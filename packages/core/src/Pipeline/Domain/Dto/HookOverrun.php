<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\OverrunKind;

/**
 * A hook that went over a budget (PRD 6.3, 13.6): the command and its version, the hook's class,
 * package and phase, which budget it went over, the time it took and the time all hooks of the
 * command had taken with it, in nanoseconds.
 */
#[Internal]
final readonly class HookOverrun
{
    /**
     * @param  class-string  $hook
     */
    public function __construct(
        public CommandName $command,
        public int $version,
        public string $hook,
        public string $package,
        public Phase $phase,
        public OverrunKind $kind,
        public int $budgetMs,
        public int $elapsedNanoseconds,
        public int $spentNanoseconds,
    ) {}

    /**
     * What went over, in plain language, for the error and the record.
     */
    public function describe(): string
    {
        return $this->kind === OverrunKind::Hook
            ? sprintf(
                'The %s hook %s of %s took %s ms, over its budget of %d ms.',
                $this->phase->value,
                $this->hook,
                $this->package,
                $this->milliseconds($this->elapsedNanoseconds),
                $this->budgetMs,
            )
            : sprintf(
                'The hooks of %s version %d took %s ms together, over the budget of %d ms all hooks of a command have; the last was the %s hook %s of %s, which took %s ms.',
                $this->command->value,
                $this->version,
                $this->milliseconds($this->spentNanoseconds),
                Hook::COMMAND_BUDGET_MS,
                $this->phase->value,
                $this->hook,
                $this->package,
                $this->milliseconds($this->elapsedNanoseconds),
            );
    }

    private function milliseconds(int $nanoseconds): string
    {
        return number_format($nanoseconds / 1_000_000, 3, '.', '');
    }
}
