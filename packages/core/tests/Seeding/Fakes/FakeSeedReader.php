<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding\Fakes;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use Override;

/**
 * The SeedReader of the seeder's action tests: the entries and the nodes a test gives it, held to
 * PostgresSeedReader by SeedReaderBehaviour. It counts its reads.
 */
final class FakeSeedReader implements SeedReader
{
    public int $reads = 0;

    /** @var array<string, true> */
    private array $entries = [];

    /** @var array<string, AggregateVersion> */
    private array $nodes = [];

    public function withEntry(EntryId $entry): self
    {
        $this->entries[$entry->toString()] = true;

        return $this;
    }

    public function withNode(NodeId $node, AggregateVersion $version): self
    {
        $this->nodes[$node->toString()] = $version;

        return $this;
    }

    public function withoutNode(NodeId $node): self
    {
        unset($this->nodes[$node->toString()]);

        return $this;
    }

    #[Override]
    public function existing(array $entries): array
    {
        $this->reads++;

        return array_values(array_filter($entries, fn (EntryId $entry): bool => isset($this->entries[$entry->toString()])));
    }

    #[Override]
    public function nodes(array $nodes): array
    {
        $this->reads++;
        $versions = [];

        foreach ($nodes as $node) {
            $versions[$node->toString()] = $this->nodes[$node->toString()] ?? null;
        }

        return $versions;
    }
}
