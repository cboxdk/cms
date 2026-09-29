<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares the surfaces a write or query action is exposed on (GUARDRAILS 2.1), for example
 * #[Action(surfaces: [Surface::Rest, Surface::Mcp])]. An action with no surface is called only by
 * the kernel's own issuers: jobs, the scheduler, subscribers, sidecars and seeds.
 *
 * A surface appears at most once. The surfaces are sorted in the order of Surface's cases, so two
 * declarations of the same surfaces are equal whatever order they list them in.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Action
{
    /** @var list<Surface> */
    public array $surfaces;

    /**
     * @param  list<Surface>  $surfaces
     */
    public function __construct(array $surfaces)
    {
        $sorted = [];

        foreach (Surface::cases() as $case) {
            $count = count(array_filter($surfaces, static fn (Surface $surface): bool => $surface === $case));

            if ($count > 1) {
                throw new InvalidArgumentException(sprintf('The surface "%s" is listed %d times in #[Action].', $case->value, $count));
            }

            if ($count === 1) {
                $sorted[] = $case;
            }
        }

        $this->surfaces = $sorted;
    }

    public function exposes(Surface $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }
}
