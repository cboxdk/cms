<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Entries\Domain\Dto\PreviewSource;
use Cbox\Cms\Core\Entries\Domain\EntryPreviews;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every EntryPreviews of the kernel does (PRD 5.4, 5.6, 5.10), held against the fake the
 * action tests use and PostgresEntryPreviews, over the EntryListingWorld: the draft of a head the
 * context reaches, or the revision asked for, with the head's numbers and state; a revision the
 * variant does not have is not found; and nothing for an entry the context does not reach, does
 * not know or has no head of the variant.
 */
trait EntryPreviewsBehaviour
{
    /**
     * Gives EntryPreviews read under the context of the reader, an actor of EntryListingWorld::READERS.
     *
     * @param  Closure(EntryPreviews): void  $read
     */
    abstract protected function previewAs(string $reader, Closure $read): void;

    #[Test]
    public function it_reads_the_draft_of_a_head_the_context_reaches_with_its_numbers_and_state(): void
    {
        $this->previewAs(EntryListingWorld::EDITOR, static function (EntryPreviews $previews): void {
            $second = EntryListingWorld::detail(EntryListingWorld::SECOND);
            $first = EntryListingWorld::detail(EntryListingWorld::FIRST);

            Assert::assertEquals(
                new PreviewSource($second->type, NodeId::fromString(EntryListingWorld::NORTH), RevisionNumber::first(), new RevisionNumber(2), ReleaseState::Released, RevisionNumber::first(), true, $second->heads[0]->fields),
                $previews->find($second->id, VariantKey::shared(), null),
            );
            Assert::assertEquals(
                new PreviewSource($first->type, NodeId::fromString(EntryListingWorld::NORTH_SECTION), RevisionNumber::first(), null, ReleaseState::Unreleased, RevisionNumber::first(), true, $first->heads[0]->fields),
                $previews->find($first->id, VariantKey::shared(), null),
            );
        });
    }

    #[Test]
    public function it_reads_the_revision_asked_for_and_finds_none_the_variant_does_not_have(): void
    {
        $this->previewAs(EntryListingWorld::EDITOR, static function (EntryPreviews $previews): void {
            $second = EntryListingWorld::detail(EntryListingWorld::SECOND);

            Assert::assertEquals(
                new PreviewSource($second->type, $second->home, RevisionNumber::first(), new RevisionNumber(2), ReleaseState::Released, new RevisionNumber(2), true, EntryListingWorld::releasedFields($second)),
                $previews->find($second->id, VariantKey::shared(), new RevisionNumber(2)),
            );
            Assert::assertEquals(
                new PreviewSource($second->type, $second->home, RevisionNumber::first(), new RevisionNumber(2), ReleaseState::Released, new RevisionNumber(3), false, null),
                $previews->find($second->id, VariantKey::shared(), new RevisionNumber(3)),
            );
        });
    }

    #[Test]
    public function it_reads_nothing_of_an_entry_out_of_reach_unknown_or_without_a_head_of_the_variant(): void
    {
        $this->previewAs(EntryListingWorld::EDITOR, static function (EntryPreviews $previews): void {
            Assert::assertNull($previews->find(EntryId::fromString(EntryListingWorld::THIRD), VariantKey::shared(), null));
            Assert::assertNull($previews->find(EntryId::fromString(EntryListingWorld::UNKNOWN), VariantKey::shared(), null));
            Assert::assertNull($previews->find(EntryId::fromString(EntryListingWorld::SECOND), VariantKey::of(new Locale('da')), null));
        });

        $this->previewAs(EntryListingWorld::NOBODY, static function (EntryPreviews $previews): void {
            Assert::assertNull($previews->find(EntryId::fromString(EntryListingWorld::SECOND), VariantKey::shared(), null));
        });
    }
}
