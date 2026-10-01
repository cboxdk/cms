<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding\Fakes;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Override;

/**
 * The SeedTargets of the seeder's action tests: nodes with their paths and kinds, of which it gives
 * those the context's regions reach and that are not mounts, sorted by id, and entries with the path
 * of their home node, of which it gives those the context's regions reach, as
 * TransactionalSeedTargets does. SeedTargetsBehaviour holds the two to each other. It counts the
 * reads of entries.
 */
final class FakeSeedTargets implements SeedTargets
{
    public int $entryReads = 0;

    /** @var array<string, array{NodePath, string}> by node id */
    private array $nodes = [];

    /** @var array<string, NodePath> the path of each entry's home by entry id */
    private array $entries = [];

    public function withNode(NodeId $node, NodePath $path, string $kind = 'section'): self
    {
        $this->nodes[$node->toString()] = [$path, $kind];

        return $this;
    }

    public function withEntry(EntryId $entry, NodePath $home): self
    {
        $this->entries[$entry->toString()] = $home;

        return $this;
    }

    #[Override]
    public function existing(AccessContext $access, array $entries): array
    {
        $this->entryReads++;

        return array_values(array_filter($entries, function (EntryId $entry) use ($access): bool {
            $home = $this->entries[$entry->toString()] ?? null;

            return $home instanceof NodePath && $access->reaches($home);
        }));
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
