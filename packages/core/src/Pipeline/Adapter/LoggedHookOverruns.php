<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookOverrun;
use Cbox\Cms\Core\Pipeline\Domain\HookOverruns;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Records a hook's overrun as a warning in the application's log (PRD 6.3, 13.6): the message is
 * hook_budget_exceeded, and the context names the command, its version, the hook, its package and
 * phase, which budget it went over, and the times, so the log answers which package makes a
 * command slow.
 */
#[Internal]
final readonly class LoggedHookOverruns implements HookOverruns
{
    public const string MESSAGE = ErrorCode::HookBudgetExceeded->value;

    public function __construct(private LoggerInterface $logger) {}

    #[Override]
    public function record(HookOverrun $overrun): void
    {
        $this->logger->warning(self::MESSAGE, [
            'command' => $overrun->command->value,
            'version' => $overrun->version,
            'hook' => $overrun->hook,
            'package' => $overrun->package,
            'phase' => $overrun->phase->value,
            'budget' => $overrun->kind->value,
            'budget_ms' => $overrun->budgetMs,
            'elapsed_ns' => $overrun->elapsedNanoseconds,
            'spent_ns' => $overrun->spentNanoseconds,
            'description' => $overrun->describe(),
        ]);
    }
}
