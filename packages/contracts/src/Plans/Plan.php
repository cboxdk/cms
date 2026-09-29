<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Plans;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a write will change: an ordered list of typed mutations and sub-plans (GUARDRAILS 2.1,
 * PRD 6.2 phase 3). WriteAction::plan() returns it, and nothing in it has been written.
 *
 * A plan composes the plans of other commands' planners, so one action can do several things
 * atomically, such as a curation that also creates a placement: the sub-plan keeps its place in
 * the order, and the whole is authorized, validated and committed as one changeset. The kernel
 * applies mutations() in order: the steps in the order given, each sub-plan's mutations where the
 * sub-plan stands.
 */
#[Experimental]
final readonly class Plan
{
    /** @var list<Mutation|Plan> */
    public array $steps;

    public function __construct(Mutation|self ...$steps)
    {
        $this->steps = array_values($steps);
    }

    /**
     * The plan that changes nothing.
     */
    public static function empty(): self
    {
        return new self;
    }

    /**
     * A new plan with the steps after this plan's steps. This plan is unchanged.
     */
    public function then(Mutation|self ...$steps): self
    {
        return new self(...$this->steps, ...$steps);
    }

    /**
     * Every mutation in the order the kernel applies them: depth first, each sub-plan's
     * mutations in place of the sub-plan.
     *
     * @return list<Mutation>
     */
    public function mutations(): array
    {
        $mutations = [];

        foreach ($this->steps as $step) {
            if ($step instanceof self) {
                array_push($mutations, ...$step->mutations());
            } else {
                $mutations[] = $step;
            }
        }

        return $mutations;
    }

    /**
     * Whether the plan, with its sub-plans, has no mutation.
     */
    public function isEmpty(): bool
    {
        return $this->mutations() === [];
    }
}
