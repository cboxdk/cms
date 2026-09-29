<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Addon;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Cbox\Cms\Core\Tests\Registry\Fixtures\Valid\CreateNote;
use Override;

/**
 * The fixture addon's validate hook on the Valid fixture's command, for the manifest tests.
 */
#[Hook(command: CreateNote::class, phase: Phase::Validate, priority: 5, budgetMs: 3)]
final readonly class RequireNoteStars implements ValidateHook
{
    #[Override]
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
