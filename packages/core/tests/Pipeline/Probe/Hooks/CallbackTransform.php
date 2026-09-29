<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks;

use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Closure;
use Override;

/**
 * A test-only transform hook whose answer is the callback's, so a test decides what it sees, how long
 * it takes on the fake stopwatch and what it answers.
 */
final readonly class CallbackTransform implements TransformHook
{
    /**
     * @param  Closure(PlanView): FieldChanges  $answer
     */
    public function __construct(private Closure $answer) {}

    #[Override]
    public function transform(PlanView $plan): FieldChanges
    {
        return ($this->answer)($plan);
    }
}
