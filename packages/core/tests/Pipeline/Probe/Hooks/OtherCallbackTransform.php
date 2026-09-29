<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks;

use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Closure;
use Override;

/**
 * A second test-only transform hook, of another class than CallbackTransform, so a test can show
 * that hooks with the same priority and package run in the order of their classes.
 */
final readonly class OtherCallbackTransform implements TransformHook
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
