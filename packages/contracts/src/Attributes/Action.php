<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares the command or query a write or query action handles and the surfaces it is exposed on
 * (GUARDRAILS 2.1), for example
 * #[Action(handles: SaveNote::class, surfaces: [Surface::Rest, Surface::Mcp])].
 *
 * cms:build registers the action in actions.php under the name and version that #[Command] or
 * #[Query] declares on the handled class, so the class a write action handles carries #[Command]
 * and the class a query action handles carries #[Query]. One command or query has one action.
 *
 * An action with no surface is called only by the kernel's own issuers: jobs, the scheduler,
 * subscribers, sidecars and seeds. A surface appears at most once, and anything that is not a case
 * of Surface throws UnknownSurface. The surfaces are sorted in the order of Surface's cases, so two
 * declarations of the same surfaces are equal whatever order they list them in.
 */
#[Attribute(Attribute::TARGET_CLASS)]
#[Experimental]
final readonly class Action
{
    /** @var list<Surface> */
    public array $surfaces;

    /**
     * @param  string  $handles  the command or query class, such as SaveNote::class
     * @param  list<Surface|string>  $surfaces
     *
     * @throws UnknownSurface when a surface is not a case of Surface
     * @throws InvalidArgumentException when a surface is listed twice or $handles is empty
     */
    public function __construct(
        public string $handles,
        array $surfaces = [],
    ) {
        if (trim($handles) === '') {
            throw new InvalidArgumentException('#[Action] names no class it handles. Give the command or query class, for example handles: SaveNote::class.');
        }

        $listed = [];

        foreach ($surfaces as $surface) {
            $listed[] = $surface instanceof Surface ? $surface : throw UnknownSurface::listed($surface);
        }

        $sorted = [];

        foreach (Surface::cases() as $case) {
            $count = count(array_filter($listed, static fn (Surface $surface): bool => $surface === $case));

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
