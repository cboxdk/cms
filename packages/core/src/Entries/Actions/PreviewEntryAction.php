<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Codecs\InvalidRecordDocument;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Codecs\RecordCodecs;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Pipeline\ReadRejected;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Dto\EntryPreview;
use Cbox\Cms\Core\Entries\Domain\EntryPreviews;
use Cbox\Cms\Core\Entries\Domain\Queries\PreviewEntry;
use Override;

/**
 * entry.preview (PRD 5.4, 8.9, 13.4): the record of an entry's draft, or of the revision asked
 * for, exactly as the delivery API would send it once released: EntryPreviews reads the head and
 * the revision's fields in the reader's context, and the installation's RecordCodecs write them as
 * the record of the entry's type at the public classification access, as the delivery API writes a
 * released row (invariant 10), so a field above public never leaves. Every actor may run it. It
 * reads, and writes nothing: no fragment is built or stored, and no cache depends on it, so the
 * answer carries no content keys.
 *
 * An entry that is absent, whose home the reader neither reaches nor owns, or that has no head of
 * the variant is entry_not_found; a revision the variant does not have is revision_not_found; and
 * one written under another schema version than the type's, or whose values the record contract
 * refuses, is revision_unreadable. It costs the one statement it reads.
 *
 * @implements QueryAction<PreviewEntry, EntryPreview>
 */
#[Action(handles: PreviewEntry::class, surfaces: [Surface::Rest, Surface::Inertia, Surface::Mcp])]
#[Internal]
final readonly class PreviewEntryAction implements QueryAction
{
    public const int COST = 1;

    public function __construct(
        private EntryPreviews $previews,
        private TypeCatalog $types,
        private RecordCodecs $records,
    ) {}

    /**
     * @param  PreviewEntry  $query
     */
    #[Override]
    public function cost(Query $query): QueryCost
    {
        return new QueryCost(self::COST);
    }

    /**
     * @param  PreviewEntry  $query
     *
     * @throws ReadRejected with entry_not_found, revision_not_found or revision_unreadable
     */
    #[Override]
    public function handle(Query $query): EntryPreview
    {
        $variant = $query->variant ?? VariantKey::shared();
        $source = $this->previews->find($query->entry, $variant, $query->revision) ?? throw ReadRejected::with(
            ErrorCode::EntryNotFound,
            sprintf('No entry %s with a variant %s is within reach: none exists, or its home lies outside the regions of your grants and you do not own it.', $query->entry->toString(), $variant->value),
            FieldPath::fromString('entry'),
        );

        if (! $source->found) {
            throw ReadRejected::with(
                ErrorCode::RevisionNotFound,
                sprintf('The variant %s of the entry %s has no revision %d. Leave the revision out to preview the draft.', $variant->value, $query->entry->toString(), $source->revision->value),
                FieldPath::fromString('revision'),
            );
        }

        $type = $this->types->find($source->type);

        if (! $type instanceof TypeDefinition || ! $source->fields instanceof FieldValues) {
            throw $this->unreadable($query, $variant, $source->revision->value, 'it was written under another schema version than the type has now');
        }

        try {
            $record = $this->records->encode(new ReadContent($query->entry, $source->home, $source->type, $source->fields), ClassificationAccess::Public);
        } catch (InvalidRecordDocument $refused) {
            throw $this->unreadable($query, $variant, $source->revision->value, $refused->getMessage());
        }

        return new EntryPreview($query->entry, $type->name, $variant, $source->revision, $source->draft, $source->released, $source->state, new JsonDocument($record));
    }

    private function unreadable(PreviewEntry $query, VariantKey $variant, int $revision, string $why): ReadRejected
    {
        return ReadRejected::with(
            ErrorCode::RevisionUnreadable,
            sprintf('The revision %d of the variant %s of the entry %s cannot be written as its type\'s record: %s.', $revision, $variant->value, $query->entry->toString(), $why),
            FieldPath::fromString('revision'),
        );
    }
}
