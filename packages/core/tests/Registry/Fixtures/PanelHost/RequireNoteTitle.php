<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\ValidateHook;
use Override;

/**
 * A validate hook of the host on notes.draft, which no addon's check may mirror.
 */
#[Hook(command: DraftNote::class, phase: Phase::Validate, priority: 0, budgetMs: 2)]
final readonly class RequireNoteTitle implements ValidateHook
{
    #[Override]
    public function validate(PlanView $plan): HookErrors
    {
        return HookErrors::none();
    }
}
