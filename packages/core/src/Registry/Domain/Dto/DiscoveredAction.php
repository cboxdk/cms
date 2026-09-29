<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * An action class declared with #[Action], as the scanner found it. The class it handles is still a
 * class name; the compiler resolves it to a registered command's or query's name and version.
 */
#[Experimental]
final readonly class DiscoveredAction
{
    public string $class;

    public string $package;

    public string $handles;

    /** @var list<Surface> */
    public array $surfaces;

    /**
     * @param  list<Surface>  $surfaces  each once, in the order of Surface's cases
     */
    public function __construct(
        string $class,
        string $package,
        public ActionKind $kind,
        string $handles,
        array $surfaces,
    ) {
        $this->class = InvalidRegistryEntry::checkClass('action class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->handles = InvalidRegistryEntry::checkClass('class the action handles', $handles);
        $this->surfaces = InvalidRegistryEntry::checkSurfaces($class, $surfaces);
    }
}
