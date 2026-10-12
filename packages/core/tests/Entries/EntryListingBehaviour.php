<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\ReleaseState;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Entries\Domain\Dto\ListedEntry;
use Cbox\Cms\Core\Entries\Domain\EntryListing;
use Cbox\Cms\Core\Entries\Domain\Queries\ListEntries;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every EntryListing of the kernel does (PRD 5.4, 5.7, 5.10), held against the fake the
 * action tests use and PostgresEntryListing, over the EntryListingWorld: an entry whose home the
 * context reaches with every head and placement, nothing for one it does not reach or does not
 * know, and pages newest first with the filters of entry.list.
 */
trait EntryListingBehaviour
{
    /**
     * Gives EntryListing read under the context of the reader, an actor of EntryListingWorld::READERS.
     *
     * @param  Closure(EntryListing): void  $read
     */
    abstract protected function listAs(string $reader, Closure $read): void;

    #[Test]
    public function it_reads_an_entry_the_context_reaches_with_its_heads_and_placements_and_none_it_does_not(): void
    {
        $this->listAs(EntryListingWorld::EDITOR, static function (EntryListing $listing): void {
            Assert::assertEquals(EntryListingWorld::detail(EntryListingWorld::SECOND), $listing->find(EntryId::fromString(EntryListingWorld::SECOND)));
            Assert::assertEquals(EntryListingWorld::detail(EntryListingWorld::FIRST), $listing->find(EntryId::fromString(EntryListingWorld::FIRST)));
            Assert::assertNull($listing->find(EntryId::fromString(EntryListingWorld::THIRD)));
            Assert::assertNull($listing->find(EntryId::fromString(EntryListingWorld::UNKNOWN)));
        });

        $this->listAs(EntryListingWorld::NOBODY, static function (EntryListing $listing): void {
            Assert::assertNull($listing->find(EntryId::fromString(EntryListingWorld::SECOND)));
        });
    }

    #[Test]
    public function it_lists_the_entries_the_context_reaches_newest_first_with_their_system_columns(): void
    {
        [$third, $second, $first] = EntryListingWorld::listed();

        $this->listAs(EntryListingWorld::ADMIN, static function (EntryListing $listing) use ($third, $second, $first): void {
            Assert::assertEquals([$third, $second, $first], $listing->page(new ListEntries, 10));
            Assert::assertEquals([$third, $second], $listing->page(new ListEntries, 2));
            Assert::assertEquals([$first], $listing->page(new ListEntries(after: EntryId::fromString(EntryListingWorld::SECOND)), 10));
            Assert::assertSame([], $listing->page(new ListEntries(after: EntryId::fromString(EntryListingWorld::FIRST)), 10));
        });

        $this->listAs(EntryListingWorld::EDITOR, static function (EntryListing $listing) use ($second, $first): void {
            Assert::assertEquals([$second, $first], $listing->page(new ListEntries, 10));
        });

        $this->listAs(EntryListingWorld::NOBODY, static function (EntryListing $listing): void {
            Assert::assertSame([], $listing->page(new ListEntries, 10));
        });
    }

    #[Test]
    public function it_filters_a_page_by_type_by_node_subtree_and_by_release_state(): void
    {
        [$third, $second, $first] = EntryListingWorld::listed();
        $type = EntryListingWorld::type()->id;

        $this->listAs(EntryListingWorld::ADMIN, static function (EntryListing $listing) use ($type, $third, $second, $first): void {
            Assert::assertEquals([$third, $second, $first], $listing->page(new ListEntries(type: $type), 10));
            Assert::assertSame([], $listing->page(new ListEntries(type: TypeId::fromString(EntryListingWorld::UNKNOWN)), 10));
            Assert::assertEquals([$second, $first], $listing->page(new ListEntries(node: NodeId::fromString(EntryListingWorld::NORTH)), 10));
            Assert::assertEquals([$first], $listing->page(new ListEntries(node: NodeId::fromString(EntryListingWorld::NORTH_SECTION)), 10));
            Assert::assertEquals([$second], $listing->page(new ListEntries(state: ReleaseState::Released), 10));
            Assert::assertEquals([$third, $first], $listing->page(new ListEntries(state: ReleaseState::Unreleased), 10));
            Assert::assertEquals([$first], $listing->page(new ListEntries(type: $type, node: NodeId::fromString(EntryListingWorld::NORTH), state: ReleaseState::Unreleased), 10));
        });

        $this->listAs(EntryListingWorld::EDITOR, static function (EntryListing $listing): void {
            Assert::assertSame([], $listing->page(new ListEntries(node: NodeId::fromString(EntryListingWorld::SOUTH)), 10));
        });
    }

    #[Test]
    public function it_gives_the_shared_head_s_release_state_and_the_newest_change_as_the_last_change(): void
    {
        $this->listAs(EntryListingWorld::ADMIN, static function (EntryListing $listing): void {
            $byId = [];

            foreach ($listing->page(new ListEntries, 10) as $entry) {
                $byId[$entry->id->toString()] = $entry;
            }

            $second = $byId[EntryListingWorld::SECOND] ?? null;
            $first = $byId[EntryListingWorld::FIRST] ?? null;

            Assert::assertInstanceOf(ListedEntry::class, $second);
            Assert::assertInstanceOf(ListedEntry::class, $first);
            Assert::assertInstanceOf(ListedEntry::class, $byId[EntryListingWorld::THIRD] ?? null);
            Assert::assertSame(ReleaseState::Released, $second->state);
            Assert::assertSame(ReleaseState::Unreleased, $first->state);
            Assert::assertEquals(EntryListingWorld::at(EntryListingWorld::RELEASED_AT), $second->changedAt);
            Assert::assertEquals(EntryListingWorld::at(EntryListingWorld::CREATED_AT), $first->changedAt);
        });
    }
}
