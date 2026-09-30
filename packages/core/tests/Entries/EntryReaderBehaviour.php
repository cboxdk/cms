<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every EntryReader does, run against PostgresEntryReader and FakeEntryReader, so the fake the
 * entry actions' tests use cannot drift from what the commands read on Postgres (GUARDRAILS 9).
 */
trait EntryReaderBehaviour
{
    public const string NODE = '0192a0c0-0000-7000-8000-0000000002a2';

    public const string OTHER_NODE = '0192a0c0-0000-7000-8000-0000000002a3';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000002e1';

    public const string BARE_ENTRY = '0192a0c0-0000-7000-8000-0000000002e2';

    public const string TYPE = '0192a0c0-0000-7000-8000-0000000002f1';

    /**
     * The reader under test, knowing NODE at version 3 below a root it may also know, the entry
     * ENTRY of TYPE homed on NODE at version 2 with the head of its shared variant at version 5 on
     * revision 4, and BARE_ENTRY of TYPE on NODE at version 1 with no head. With $snapshots, the
     * head keeps its revision number with its snapshot, as for a type without revisions. With
     * $released, a release wrote revision 5, a published revision, after the draft, and the head's
     * release state is $released.
     */
    abstract protected function entryReader(bool $snapshots = false, ?string $released = null): EntryReader;

    #[Test]
    public function it_reads_a_node_s_version_and_nothing_for_a_node_it_does_not_know(): void
    {
        $reader = $this->entryReader();

        Assert::assertEquals(new AggregateVersion(3), $reader->node(NodeId::fromString(self::NODE)));
        Assert::assertNull($reader->node(NodeId::fromString(self::OTHER_NODE)));
    }

    #[Test]
    public function it_reads_an_entry_with_the_head_of_the_variant_and_its_current_revision(): void
    {
        foreach ([false, true] as $snapshots) {
            $entry = $this->entryReader($snapshots)->entry(EntryId::fromString(self::ENTRY), VariantKey::shared());

            Assert::assertEquals(new StoredEntry(
                EntryId::fromString(self::ENTRY),
                TypeId::fromString(self::TYPE),
                NodeId::fromString(self::NODE),
                new AggregateVersion(2),
                new StoredHead(new AggregateVersion(5), new RevisionNumber(4), new RevisionNumber(4), null),
            ), $entry);
        }
    }

    #[Test]
    public function it_reads_the_highest_revision_number_and_the_released_revision_of_a_released_head(): void
    {
        $released = $this->entryReader(released: 'released')->entry(EntryId::fromString(self::ENTRY), VariantKey::shared())?->head;
        $withdrawn = $this->entryReader(released: 'withdrawn')->entry(EntryId::fromString(self::ENTRY), VariantKey::shared())?->head;

        Assert::assertEquals(new StoredHead(new AggregateVersion(5), new RevisionNumber(4), new RevisionNumber(5), new RevisionNumber(5)), $released);
        Assert::assertEquals(new StoredHead(new AggregateVersion(5), new RevisionNumber(4), new RevisionNumber(5), null), $withdrawn);
    }

    #[Test]
    public function it_reads_an_entry_without_a_head_for_a_variant_it_has_none_of(): void
    {
        $reader = $this->entryReader();

        Assert::assertNull($reader->entry(EntryId::fromString(self::BARE_ENTRY), VariantKey::shared())?->head);
        Assert::assertNull($reader->entry(EntryId::fromString(self::ENTRY), VariantKey::of(new Locale('da')))?->head);
        Assert::assertEquals(new AggregateVersion(1), $reader->entry(EntryId::fromString(self::BARE_ENTRY), VariantKey::shared())?->version);
    }

    #[Test]
    public function it_reads_nothing_for_an_entry_it_does_not_know(): void
    {
        Assert::assertNull($this->entryReader()->entry(EntryId::fromString('0192a0c0-0000-7000-8000-0000000002e9'), VariantKey::shared()));
    }
}
