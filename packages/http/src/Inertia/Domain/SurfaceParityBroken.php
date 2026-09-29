<?php

declare(strict_types=1);

namespace Cbox\Cms\Http\Inertia\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use LogicException;

/**
 * The registry exposes an action through the Inertia profile but not through REST. The panel and
 * REST stay in parity: everything the panel can do, REST can do too, because the mobile and
 * desktop apps build on REST (decided by Sylvester on 29 September 2026). Add Surface::Rest to the
 * action's #[Action] and run cms:build.
 */
#[Internal]
final class SurfaceParityBroken extends LogicException
{
    public static function inertiaWithoutRest(ActionEntry $first, ActionEntry ...$more): self
    {
        $actions = array_map(
            static fn (ActionEntry $entry): string => sprintf('%s (%s version %d)', $entry->class, $entry->command->value, $entry->commandVersion),
            [$first, ...$more],
        );

        return new self(sprintf(
            'The Inertia profile exposes %s, but REST does not. Everything the panel can do, REST can do too: add Surface::Rest to #[Action] and run cms:build.',
            implode(', ', $actions),
        ));
    }
}
