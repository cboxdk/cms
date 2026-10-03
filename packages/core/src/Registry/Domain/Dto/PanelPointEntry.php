<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;
use Cbox\Cms\Core\Registry\Domain\PointStability;

/**
 * A panel point in the panel registry, panel.php: its declaration as #[PanelPoint] gives it, the
 * class of its props and that class's package, the stability its class declares, and the
 * contributions to it in the order the host renders them: priority with the lowest first, then the
 * addon's namespace, then the contribution's id.
 */
#[Experimental]
final readonly class PanelPointEntry
{
    public string $class;

    public string $package;

    /** @var list<PanelFill> */
    public array $fills;

    /**
     * @param  list<PanelFill>  $fills  each contribution once, in any order
     *
     * @throws InvalidRegistryEntry for a contribution given twice
     */
    public function __construct(
        public PanelPoint $declaration,
        string $class,
        string $package,
        public PointStability $stability,
        array $fills = [],
    ) {
        $this->class = InvalidRegistryEntry::checkClass('panel point class', $class);
        $this->package = InvalidRegistryEntry::checkPackage($package);

        $byId = [];

        foreach ($fills as $fill) {
            if (array_key_exists($fill->contribution->value, $byId)) {
                throw new InvalidRegistryEntry(sprintf('The panel point %s lists the contribution %s twice.', $this->id()->toString(), $fill->contribution->value));
            }

            $byId[$fill->contribution->value] = $fill;
        }

        // A namespace is lowercase letters and digits, which sort after the dot that ends it, so
        // the id's string order is the order by namespace, then by the rest of the id.
        usort($byId, static fn (PanelFill $a, PanelFill $b): int => [$a->priority, $a->contribution->value] <=> [$b->priority, $b->contribution->value]);

        $this->fills = $byId;
    }

    public function id(): PointId
    {
        return $this->declaration->id();
    }

    public function page(): PageName
    {
        return $this->declaration->pageName();
    }
}
