<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Seeding\Domain\SeedReader;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SeedReader does, the fake of the seeder's action tests and the Postgres reader alike:
 * the entries of a list that exist, in the list's order, and the version of each node of a list,
 * null for a node absent or out of the actor's reach.
 */
trait SeedReaderBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000047a1';

    public const string NODE = '0192a0c0-0000-7000-8000-0000000047a2';

    public const string OTHER_NODE = '0192a0c0-0000-7000-8000-0000000047a3';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000047e1';

    public const string OTHER_ENTRY = '0192a0c0-0000-7000-8000-0000000047e2';

    public const string ABSENT_ENTRY = '0192a0c0-0000-7000-8000-0000000047e3';

    public const string TYPE = '0192a0c0-0000-7000-8000-0000000047f1';

    /**
     * The reader under test, knowing NODE at version 3 below ROOT, the entries ENTRY and
     * OTHER_ENTRY homed on NODE, and not OTHER_NODE, which is out of the actor's reach.
     */
    abstract protected function seedReader(): SeedReader;

    #[Test]
    public function it_gives_the_entries_of_a_list_that_exist_in_the_list_s_order(): void
    {
        $reader = $this->seedReader();
        $existing = $reader->existing([
            EntryId::fromString(self::OTHER_ENTRY),
            EntryId::fromString(self::ABSENT_ENTRY),
            EntryId::fromString(self::ENTRY),
        ]);

        Assert::assertSame([self::OTHER_ENTRY, self::ENTRY], array_map(static fn (EntryId $entry): string => $entry->toString(), $existing));
        Assert::assertSame([], $reader->existing([]));
        Assert::assertSame([], $reader->existing([EntryId::fromString(self::ABSENT_ENTRY)]));
    }

    #[Test]
    public function it_gives_the_version_of_each_node_and_null_for_one_it_does_not_reach(): void
    {
        $versions = $this->seedReader()->nodes([NodeId::fromString(self::OTHER_NODE), NodeId::fromString(self::NODE)]);

        Assert::assertEquals([self::OTHER_NODE => null, self::NODE => new AggregateVersion(3)], $versions);
        Assert::assertSame([], $this->seedReader()->nodes([]));
    }
}
