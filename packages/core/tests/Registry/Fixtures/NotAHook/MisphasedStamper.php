<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotAHook;

use Cbox\Cms\Contracts\Attributes\Hook;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Hooks\FieldChanges;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Hooks\TransformHook;
use Override;

/**
 * A hook declared for the validate phase that implements the transform phase's interface.
 */
#[Hook(command: StampNote::class, phase: Phase::Validate, priority: 0, budgetMs: 1)]
final readonly class MisphasedStamper implements TransformHook
{
    #[Override]
    public function transform(PlanView $plan): FieldChanges
    {
        return FieldChanges::none();
    }
}
