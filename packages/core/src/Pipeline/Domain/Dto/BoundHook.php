<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Pipeline\Domain\InvalidHook;

/**
 * A hook as the command pipeline runs it: the hook, its package, and the phase, priority and
 * budget its #[Hook] declares. The hook implements the interface of its phase.
 */
#[Internal]
final readonly class BoundHook
{
    /** @var class-string */
    public string $class;

    /**
     * @throws InvalidHook when the hook does not implement its phase's interface or the budget is out of range
     */
    public function __construct(
        public AuthorizeHook|TransformHook|ValidateHook $hook,
        public string $package,
        public Phase $phase,
        public int $priority,
        public int $budgetMs,
    ) {
        $this->class = $hook::class;

        if (! is_a($hook, $phase->hookInterface())) {
            throw InvalidHook::phase($this->class, $phase);
        }

        if ($budgetMs < 1 || $budgetMs > Hook::MAX_BUDGET_MS) {
            throw InvalidHook::budget($this->class, $budgetMs);
        }
    }

    /**
     * The order of hooks within a phase (PRD 6.3): the lowest priority first, then the package
     * name, then the class.
     */
    public static function order(self $one, self $other): int
    {
        return [$one->priority, $one->package, $one->class] <=> [$other->priority, $other->package, $other->class];
    }

    /**
     * The budget in nanoseconds, as the stopwatch measures.
     */
    public function budgetNanoseconds(): int
    {
        return $this->budgetMs * 1_000_000;
    }

    public function authorize(PlanView $plan): HookDecision
    {
        if (! $this->hook instanceof AuthorizeHook) {
            throw InvalidHook::phase($this->class, Phase::Authorize);
        }

        return $this->hook->authorize($plan);
    }

    public function transform(PlanView $plan): FieldChanges
    {
        if (! $this->hook instanceof TransformHook) {
            throw InvalidHook::phase($this->class, Phase::Transform);
        }

        return $this->hook->transform($plan);
    }

    public function validate(PlanView $plan): HookErrors
    {
        if (! $this->hook instanceof ValidateHook) {
            throw InvalidHook::phase($this->class, Phase::Validate);
        }

        return $this->hook->validate($plan);
    }
}
