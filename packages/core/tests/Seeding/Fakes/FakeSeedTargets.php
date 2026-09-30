<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Override;

/**
 * The SeedTargets of the seeder's action tests: nodes with their paths and kinds, of which it gives
 * those the context's regions reach and that are not mounts, sorted by id, as
 * TransactionalSeedTargets does. SeedTargetsBehaviour holds the two to each other.
 */
final class FakeSeedTargets implements SeedTargets
{
    /** @var array<string, array{NodePath, string}> by node id */
    private array $nodes = [];

    public function withNode(NodeId $node, NodePath $path, string $kind = 'section'): self
    {
        $this->nodes[$node->toString()] = [$path, $kind];

        return $this;
    }

    #[Override]
    public function nodes(AccessContext $access): array
    {
        $reached = [];

        foreach ($this->nodes as $id => [$path, $kind]) {
            if ($kind !== 'mount' && $access->reaches($path)) {
                $reached[] = $id;
            }
        }

        sort($reached, SORT_STRING);

        return array_map(NodeId::fromString(...), $reached);
    }
}
