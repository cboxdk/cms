<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\UnknownHookCommand;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Override;

/**
 * A hook for a real #[Command] class that lives outside this scan root, so a build of this root
 * alone has no such command.
 */
#[Hook(command: CreateNote::class, phase: Phase::Validate, priority: 0, budgetMs: 1)]
final readonly class OrphanHook implements ValidateHook
{
    #[Override]
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
