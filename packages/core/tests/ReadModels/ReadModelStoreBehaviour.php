<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReadModels;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\ReadModels\Domain\EntryRange;
use Cbox\Cms\Core\ReadModels\Domain\ReadModelStore;
use Cbox\Cms\Core\ReadModels\Domain\RebuildRefused;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every ReadModelStore does (PRD 4.1, invariant 22), held against the fake the action tests
 * use and the adapter on real Postgres, with the workbench's fixture types: a plan is the type's
 * entries in id order, in ranges of at most the chunk size, and none of another type's; a rebuild
 * of a range counts its entries and variant heads, and refuses a payload at another schema version
 * with rebuild_schema_version_unsupported. The entries are RebuildWorld::entry($number).
 */
trait ReadModelStoreBehaviour
{
    abstract protected function readModelStore(): ReadModelStore;

    /**
     * The context the store reads and writes under, which reaches every entry the test adds.
     */
    abstract protected function rebuildAccess(): AccessContext;

    /**
     * Adds an entry of the type, with its shared variant at the type's current schema version,
     * for each number, in the order given.
     */
    abstract protected function addEntries(TypeDefinition $type, int ...$numbers): void;

    /**
     * Makes the payload the rebuild reads of the entry's shared variant one written at another
     * schema version.
     */
    abstract protected function atOtherVersion(TypeDefinition $type, int $number): void;

    #[Test]
    public function a_plan_is_the_types_entries_in_id_order_in_ranges_of_at_most_the_chunk_size(): void
    {
        $measurement = EntryWorld::type(EntryWorld::MEASUREMENT);
        $this->addEntries($measurement, 5, 1, 3, 2, 4);

        Assert::assertEquals([
            new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(2)),
            new EntryRange(RebuildWorld::entry(3), RebuildWorld::entry(4)),
            new EntryRange(RebuildWorld::entry(5), RebuildWorld::entry(5)),
        ], $this->readModelStore()->plan($measurement, $this->rebuildAccess(), 2));
        Assert::assertEquals(
            [new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(5))],
            $this->readModelStore()->plan($measurement, $this->rebuildAccess(), 5),
        );
    }

    #[Test]
    public function a_plan_leaves_out_the_entries_of_other_types_and_is_empty_without_entries(): void
    {
        $article = EntryWorld::type(EntryWorld::ARTICLE);
        $measurement = EntryWorld::type(EntryWorld::MEASUREMENT);

        Assert::assertSame([], $this->readModelStore()->plan($measurement, $this->rebuildAccess(), 10));

        $this->addEntries($article, 1, 4);
        $this->addEntries($measurement, 2, 3);

        Assert::assertEquals(
            [new EntryRange(RebuildWorld::entry(2), RebuildWorld::entry(3))],
            $this->readModelStore()->plan($measurement, $this->rebuildAccess(), 10),
        );
        Assert::assertEquals(
            [new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(1)), new EntryRange(RebuildWorld::entry(4), RebuildWorld::entry(4))],
            $this->readModelStore()->plan($article, $this->rebuildAccess(), 1),
        );
    }

    #[Test]
    public function a_rebuild_counts_the_entries_and_variant_heads_of_its_range(): void
    {
        $article = EntryWorld::type(EntryWorld::ARTICLE);
        $this->addEntries($article, 1, 2, 3);
        $range = new EntryRange(RebuildWorld::entry(1), RebuildWorld::entry(2));

        $result = $this->readModelStore()->rebuild($article, $this->rebuildAccess(), $range);
        $empty = $this->readModelStore()->rebuild($article, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry(7), RebuildWorld::entry(9)));

        Assert::assertEquals($range->chunk(), $result->chunk);
        Assert::assertSame([2, 2], [$result->entries, $result->variants]);
        Assert::assertLessThan(2_000, $result->milliseconds);
        Assert::assertSame([0, 0], [$empty->entries, $empty->variants]);
    }

    #[Test]
    public function a_rebuild_refuses_a_payload_at_another_schema_version(): void
    {
        foreach ([EntryWorld::ARTICLE => [1, 2], EntryWorld::MEASUREMENT => [3, 4]] as $name => [$first, $second]) {
            $type = EntryWorld::type($name);
            $this->addEntries($type, $first, $second);
            $this->atOtherVersion($type, $second);

            try {
                $this->readModelStore()->rebuild($type, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry($first), RebuildWorld::entry($second)));
                Assert::fail(sprintf('The rebuild of %s should have refused the payload at another schema version.', $name));
            } catch (RebuildRefused $refused) {
                Assert::assertSame(RebuildRefused::CODE_SCHEMA_VERSION, $refused->errorCode);
                Assert::assertStringContainsString(RebuildWorld::entry($second)->toString(), $refused->getMessage());
                Assert::assertStringContainsString(sprintf('schema version %d', $type->version + 1), $refused->getMessage());
            }

            $result = $this->readModelStore()->rebuild($type, $this->rebuildAccess(), new EntryRange(RebuildWorld::entry($first), RebuildWorld::entry($first)));
            Assert::assertSame(1, $result->entries);
        }
    }
}
