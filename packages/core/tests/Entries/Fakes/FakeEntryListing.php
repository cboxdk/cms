<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries\Fakes;

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryDetail;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Entries\Domain\EntryListing;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use DateTimeImmutable;
use Override;

/**
 * EntryListing in memory (EntryListingBehaviour holds it to PostgresEntryListing): a test adds
 * each entry as entry.get gives it, with its last change, and the fake gives the entries whose
 * home one of the context's roots is above, as the explicit access predicate does, with every
 * head and placement; a page lists them newest first by id and applies the query's filters over
 * the tree the fake was given.
 */
final class FakeEntryListing implements EntryListing
{
    /** @var array<string, array{EntryDetail, DateTimeImmutable}> by entry id */
    private array $entries = [];

    /**
     * @param  array<string, list<string>>  $ancestry  the ancestors of each node, itself included, from the root down
     * @param  list<NodeId>  $roots  the roots of the context's regions
     */
    public function __construct(private readonly array $ancestry, private readonly array $roots) {}

    public function add(EntryDetail $detail, DateTimeImmutable $changedAt): self
    {
        $this->entries[$detail->id->toString()] = [$detail, $changedAt];

        return $this;
    }

    #[Override]
    public function find(EntryId $entry): ?EntryDetail
    {
        $stored = $this->entries[$entry->toString()] ?? null;

        return $stored !== null && $this->reaches($stored[0]->home) ? $stored[0] : null;
    }

    #[Override]
    public function page(ListEntries $query, int $limit): array
    {
        $ids = array_keys($this->entries);
        rsort($ids);
        $listed = [];

        foreach ($ids as $id) {
            [$detail, $changedAt] = $this->entries[$id];
            $state = $detail->heads[0]->state ?? null;

            if (! $this->reaches($detail->home)
                || ($query->after instanceof EntryId && strcmp($id, $query->after->toString()) >= 0)
                || ($query->type instanceof TypeId && ! $detail->type->equals($query->type))
                || ($query->node instanceof NodeId && (! $this->reaches($query->node) || ! $this->below($detail->home, $query->node)))
                || ($query->state instanceof ReleaseState && $state !== $query->state)) {
                continue;
            }

            $listed[] = new ListedEntry($detail->id, $detail->type, $detail->home, $detail->lifecycle, $state, $changedAt, $detail->version);
        }

        return array_slice($listed, 0, max($limit, 0));
    }

    private function reaches(NodeId $node): bool
    {
        return array_any($this->roots, fn (NodeId $root): bool => $this->below($node, $root));
    }

    private function below(NodeId $node, NodeId $ancestor): bool
    {
        return in_array($ancestor->toString(), $this->ancestry[$node->toString()] ?? [], true);
    }
}
