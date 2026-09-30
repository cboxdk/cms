<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries\Fakes;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Override;

/**
 * The entries and nodes a test puts on it, in memory, read as the Postgres reader reads them: an
 * entry with the head of the variant asked for, or none when that variant has no head.
 */
final class FakeEntryReader implements EntryReader
{
    /** @var array<string, AggregateVersion> by node id */
    private array $nodes = [];

    /** @var array<string, array{TypeId, NodeId, AggregateVersion}> by entry id */
    private array $entries = [];

    /** @var array<string, array<string, StoredHead>> by entry id and variant */
    private array $heads = [];

    public function withNode(NodeId $node, AggregateVersion $version): self
    {
        $this->nodes[$node->toString()] = $version;

        return $this;
    }

    public function withEntry(EntryId $entry, TypeId $type, NodeId $home, AggregateVersion $version): self
    {
        $this->entries[$entry->toString()] = [$type, $home, $version];

        return $this;
    }

    public function withHead(EntryId $entry, VariantKey $variant, StoredHead $head): self
    {
        $this->heads[$entry->toString()][$variant->value] = $head;

        return $this;
    }

    #[Override]
    public function entry(EntryId $entry, VariantKey $variant): ?StoredEntry
    {
        $stored = $this->entries[$entry->toString()] ?? null;

        if ($stored === null) {
            return null;
        }

        [$type, $home, $version] = $stored;

        return new StoredEntry($entry, $type, $home, $version, $this->heads[$entry->toString()][$variant->value] ?? null);
    }

    #[Override]
    public function node(NodeId $node): ?AggregateVersion
    {
        return $this->nodes[$node->toString()] ?? null;
    }
}
