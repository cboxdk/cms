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
 * The command is a class declared with #[Command]. Hooks run by priority, then package name.
 * The budget is at most 20 ms per hook (PRD 6.3); a lower budget is allowed.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Hook
{
    public const int MAX_BUDGET_MS = 20;

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
