<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares the surfaces cms:build generates for an action (GUARDRAILS 2.1), for example
 * #[Action(surfaces: [Surface::Rest, Surface::Mcp])].
 *
 * An empty list means the action has no generated surface; jobs, the scheduler and other
 * internal callers still call it directly.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Action
{
    /**
     * @param  list<Surface>  $surfaces
     */
    public function __construct(
        public array $surfaces,
    ) {
        $seen = [];

        foreach ($surfaces as $surface) {
            if (isset($seen[$surface->value])) {
                throw new InvalidArgumentException(sprintf(
                    'Surface "%s" is declared more than once.',
                    $surface->name,
                ));
            }

            $seen[$surface->value] = true;
        }
    }

    public function exposes(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }
}
