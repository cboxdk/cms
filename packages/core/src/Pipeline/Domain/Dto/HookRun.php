<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Pipeline\Domain\HookBudget;

/**
 * Where the hooks of one command stand (PRD 6.2 phases 2 to 5, 6.3): the plan as the transforms
 * left it, the time the hooks have taken, the errors the validate hooks added, and the rejection
 * that stopped the command, if one did: a denial, a refused change or an overrun.
 */
#[Internal]
final readonly class HookRun
{
    /**
     * @param  list<CatalogError>  $errors
     */
    public function __construct(
        public Plan $plan,
        public HookBudget $budget = new HookBudget,
        public array $errors = [],
        public ?CatalogError $rejection = null,
    ) {}

    public function rejected(): bool
    {
        return $this->rejection instanceof CatalogError;
    }

    public function charged(HookBudget $budget): self
    {
        return new self($this->plan, $budget, $this->errors, $this->rejection);
    }

    public function withPlan(Plan $plan): self
    {
        return new self($plan, $this->budget, $this->errors, $this->rejection);
    }

    public function withErrors(CatalogError ...$errors): self
    {
        return new self($this->plan, $this->budget, [...$this->errors, ...array_values($errors)], $this->rejection);
    }

    public function rejectedWith(CatalogError $rejection): self
    {
        return new self($this->plan, $this->budget, $this->errors, $rejection);
    }
}
