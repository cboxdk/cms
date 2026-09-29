<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Declares which command a hook runs for, in which phase, in which order and with which time
 * budget (GUARDRAILS 2.4, PRD 6.3), for example
 * #[Hook(command: ReleaseVariant::class, phase: Phase::Transform, priority: 10, budgetMs: 20)].
 *
 * The command is a class declared with #[Command], and the class implements the interface of its
 * phase: AuthorizeHook, TransformHook or ValidateHook. Within a phase, hooks run by priority with
 * the lowest first, then by package name, then by class. The budget is at most 20 ms per hook
 * (PRD 6.3); a lower budget is allowed. All hooks of one command together have 100 ms. A hook
 * that takes longer than its budget, or that takes the hooks of its command past theirs, rejects
 * the command with hook_budget_exceeded.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Hook
{
    public const int MAX_BUDGET_MS = 20;

    /** The time all hooks of one command have together, in milliseconds (PRD 6.3). */
    public const int COMMAND_BUDGET_MS = 100;

    /**
     * The command class the hook runs for.
     *
     * @var class-string
     */
    public string $command;

    public function __construct(
        string $command,
        public Phase $phase,
        public int $priority,
        public int $budgetMs,
    ) {
        if (! class_exists($command)) {
            throw new InvalidArgumentException(sprintf('Hook command "%s" is not a class.', $command));
        }

        $this->command = $command;

        if (new ReflectionClass($command)->getAttributes(Command::class) === []) {
            throw new InvalidArgumentException(sprintf(
                'Hook command "%s" is not declared with #[Command].',
                $command,
            ));
        }

        if ($budgetMs < 1 || $budgetMs > self::MAX_BUDGET_MS) {
            throw new InvalidArgumentException(sprintf(
                'Hook budget for "%s" is %d ms. It must be between 1 and %d ms.',
                $command,
                $budgetMs,
                self::MAX_BUDGET_MS,
            ));
        }
    }
}
