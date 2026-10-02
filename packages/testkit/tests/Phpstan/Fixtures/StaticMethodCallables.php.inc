<?php

declare(strict_types=1);

// A first-class callable of a static method for each rule StaticMethodCallablesRule hands one to:
// a read of the system clock, a new UUID and IO in a hook.

namespace Fixture\StaticMethodCallables {
    use Carbon\Carbon;
    use Cbox\Cms\Contracts\Hooks\HookErrors;
    use Cbox\Cms\Contracts\Hooks\PlanView;
    use Cbox\Cms\Contracts\Hooks\ValidateHook;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;

    final readonly class TakesStaticMethods
    {
        public function clockAndUuid(): void
        {
            Carbon::now(...);
            Str::uuid(...);
        }
    }

    final readonly class SelectsInAHook implements ValidateHook
    {
        public function validate(PlanView $plan): HookErrors
        {
            $select = DB::select(...);

            return $select === null ? HookErrors::none() : HookErrors::none();
        }
    }
}
