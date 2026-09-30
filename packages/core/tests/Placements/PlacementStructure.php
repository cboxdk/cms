<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;

/**
 * The structure PlacementWorld::seed() writes: two sites, each with a section below its root.
 */
final readonly class PlacementStructure
{
    public function __construct(
        public StructureSite $north,
        public StructureNode $northSection,
        public StructureSite $south,
        public StructureNode $southSection,
    ) {}
}
