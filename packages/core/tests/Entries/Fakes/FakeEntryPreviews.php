<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries\Fakes;

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Entries\Domain\Dto\PreviewSource;
use Cbox\Cms\Core\Entries\Domain\EntryPreviews;
use Override;

/**
 * EntryPreviews in memory (EntryPreviewsBehaviour holds it to PostgresEntryPreviews): a test adds
 * each variant head with its type, home, draft and released numbers, release state and the fields
 * of each revision it has, by number, null for a revision the type cannot read; the fake gives the
 * heads whose home one of the context's roots is above, as the explicit access predicate does, and
 * counts the reads.
 */
final class FakeEntryPreviews implements EntryPreviews
{
    /** The number of find() calls. */
    public int $reads = 0;

    /** @var array<string, array{TypeId, NodeId, RevisionNumber, ?RevisionNumber, ReleaseState, array<int, ?FieldValues>}> by "<entry> <variant>" */
    private array $heads = [];

    /**
     * @param  array<string, list<string>>  $ancestry  the ancestors of each node, itself included, from the root down
     * @param  list<NodeId>  $roots  the roots of the context's regions
     */
    public function __construct(private readonly array $ancestry, private readonly array $roots) {}

    /**
     * @param  array<int, FieldValues|null>  $revisions  the fields of each revision, by number; null for one the type cannot read
     */
    public function add(EntryId $entry, VariantKey $variant, TypeId $type, NodeId $home, RevisionNumber $draft, ?RevisionNumber $released, ReleaseState $state, array $revisions): self
    {
        $this->heads[$entry->toString().' '.$variant->value] = [$type, $home, $draft, $released, $state, $revisions];

        return $this;
    }

    #[Override]
    public function find(EntryId $entry, VariantKey $variant, ?RevisionNumber $revision): ?PreviewSource
    {
        $this->reads++;
        $head = $this->heads[$entry->toString().' '.$variant->value] ?? null;

        if ($head === null || ! array_any($this->roots, fn (NodeId $root): bool => in_array($root->toString(), $this->ancestry[$head[1]->toString()] ?? [], true))) {
            return null;
        }

        [$type, $home, $draft, $released, $state, $revisions] = $head;
        $number = $revision ?? $draft;
        $found = array_key_exists($number->value, $revisions);

        return new PreviewSource($type, $home, $draft, $released, $state, $number, $found, $found ? $revisions[$number->value] : null);
    }
}
