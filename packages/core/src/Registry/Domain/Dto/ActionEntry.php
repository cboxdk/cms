<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * An action class declared with #[Action], and the surfaces cms:build generates for it
 * (GUARDRAILS 2.1). The surfaces are kept in the order of the Surface cases, whatever order the
 * attribute lists them in.
 */
#[Experimental]
final readonly class ActionEntry
{
    public string $class;

    public string $package;

    /** @var list<Surface> */
    public array $surfaces;

    /**
     * @param  list<Surface>  $surfaces
     */
    public function __construct(string $class, string $package, array $surfaces)
    {
        $this->class = InvalidRegistryEntry::checkClass('action class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);

        $ordered = [];

        foreach (Surface::cases() as $surface) {
            $count = count(array_filter($surfaces, static fn (Surface $declared): bool => $declared === $surface));

            if ($count > 1) {
                throw InvalidRegistryEntry::because(sprintf('Action "%s" lists surface "%s" more than once.', $class, $surface->value));
            }

            if ($count === 1) {
                $ordered[] = $surface;
            }
        }

        $this->surfaces = $ordered;
    }
}
