<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Override;

/**
 * The kernel's VariantHeadReader, bound in cbox-cms.contracts (PRD 5.4, 13.4): the entry with the
 * head of the variant through the EntryReader, the type through the TypeCatalog and the fields of
 * the head's draft revision through RevisionContents, each on the caller's transaction and under
 * its actor context, so row level security decides what the caller sees. Two lookups by key,
 * whatever else the entry holds.
 */
#[Experimental]
final readonly class ReaderVariantHeads implements VariantHeadReader
{
    public function __construct(
        private EntryReader $entries,
        private RevisionContents $revisions,
        private TypeCatalog $types,
    ) {}

    #[Override]
    public function head(EntryId $entry, VariantKey $variant): ?VariantHead
    {
        $stored = $this->entries->entry($entry, $variant);
        $head = $stored?->head;

        if (! $stored instanceof StoredEntry || ! $head instanceof StoredHead) {
            return null;
        }

        $type = $this->types->find($stored->type);

        if (! $type instanceof TypeDefinition) {
            return null;
        }

        return new VariantHead(
            $entry,
            $stored->type,
            $stored->home,
            $variant,
            $head->version,
            $head->revision,
            $head->latest,
            $head->released,
            $head->state(),
            $this->revisions->find($entry, $variant, $head->revision, $type)?->fields,
        );
    }
}
