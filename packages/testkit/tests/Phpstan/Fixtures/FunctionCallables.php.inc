<?php

declare(strict_types=1);

// A first-class callable of a function for each rule FunctionCallablesRule hands one to: a read
// of the system clock, a new UUID and IO in a hook.

namespace Fixture\FunctionCallables {
    use Cbox\Cms\Contracts\Hooks\HookErrors;
    use Cbox\Cms\Contracts\Hooks\PlanView;
    use Cbox\Cms\Contracts\Hooks\ValidateHook;

    final readonly class TakesFunctions
    {
        public function clockAndUuid(): void
        {
            time(...);
            uuid_create(...);
        }
    }

    final readonly class ReadsAFileInAHook implements ValidateHook
    {
        public function validate(PlanView $plan): HookErrors
        {
            $read = file_get_contents(...);

            return $read === null ? HookErrors::none() : HookErrors::none();
        }
    }
}
