<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\NotFinalReadonly;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;

/**
 * #[Action] on a readonly class that is not final, so a subclass could change what the registered
 * action does (GUARDRAILS 2.1).
 */
#[Action(surfaces: [Surface::Rest])]
readonly class OpenAction
{
    public function handle(MutableCommand $command): string
    {
        return $command->title;
    }
}
