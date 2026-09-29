<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks;

use Cbox\Cms\Contracts\Hooks\AuthorizeHook;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Closure;
use Override;

/**
 * A test-only authorize hook whose answer is the callback's, so a test decides what it sees, how long
 * it takes on the fake stopwatch and what it answers.
 */
final readonly class CallbackAuthorize implements AuthorizeHook
{
    /**
     * @param  Closure(PlanView): HookDecision  $answer
     */
    public function __construct(private Closure $answer) {}

    #[Override]
    public function authorize(PlanView $plan): HookDecision
    {
        return ($this->answer)($plan);
    }
}
