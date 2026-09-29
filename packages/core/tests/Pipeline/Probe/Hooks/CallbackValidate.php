<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks;

use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Closure;
use Override;

/**
 * A test-only validate hook whose answer is the callback's, so a test decides what it sees, how long
 * it takes on the fake stopwatch and what it answers.
 */
final readonly class CallbackValidate implements ValidateHook
{
    /**
     * @param  Closure(PlanView): HookErrors  $answer
     */
    public function __construct(private Closure $answer) {}

    #[Override]
    public function validate(PlanView $plan): HookErrors
    {
        return ($this->answer)($plan);
    }
}
