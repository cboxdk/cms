<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Core\Pipeline\Domain\RelationTargets;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every RelationTargets does, run against PostgresRelationTargets on Postgres and
 * FakeRelationTargets, so the fake the pipeline's action tests use cannot drift from what the
 * pipeline reads (GUARDRAILS 9): the type of each entry that exists and the actor can read, and
 * none for an entry that does not exist or lies outside the actor's regions, and the same for the
 * nodes a link names.
 */
trait RelationTargetsBehaviour
{
    /** An entry of ARTICLE_TYPE the actor can read. */
    public const string ARTICLE = '0192a0c0-0000-7000-8000-0000000005e1';

    /** An entry of NOTE_TYPE the actor can read. */
    public const string NOTE = '0192a0c0-0000-7000-8000-0000000005e2';

    /** An entry of NOTE_TYPE on a node outside the actor's regions. */
    public const string FAR_NOTE = '0192a0c0-0000-7000-8000-0000000005e3';

    /** An entry that does not exist. */
    public const string MISSING = '0192a0c0-0000-7000-8000-0000000005e9';

    /** A node the actor can read. */
    public const string HOME_NODE = '0192a0c0-0000-7000-8000-0000000005a2';

    /** A node outside the actor's regions. */
    public const string FAR_NODE = '0192a0c0-0000-7000-8000-0000000005a3';

    /** A node that does not exist. */
    public const string MISSING_NODE = '0192a0c0-0000-7000-8000-0000000005a9';

    public const string ARTICLE_TYPE = '0192a0c0-0000-7000-8000-0000000005c1';

    public const string NOTE_TYPE = '0192a0c0-0000-7000-8000-0000000005c2';

    /**
     * The targets under test, knowing the entries of the constants above.
     */
    abstract protected function relationTargets(): RelationTargets;

    #[Test]
    public function it_gives_the_type_of_each_entry_the_actor_can_read(): void
    {
        $types = $this->relationTargets()->types([EntryId::fromString(self::ARTICLE), EntryId::fromString(self::NOTE)]);

        Assert::assertEquals(TypeId::fromString(self::ARTICLE_TYPE), $types->of(EntryId::fromString(self::ARTICLE)));
        Assert::assertEquals(TypeId::fromString(self::NOTE_TYPE), $types->of(EntryId::fromString(self::NOTE)));
    }

    #[Test]
    public function it_gives_no_type_for_an_entry_that_does_not_exist_or_lies_outside_the_actor_s_regions(): void
    {
        $types = $this->relationTargets()->types([EntryId::fromString(self::MISSING), EntryId::fromString(self::FAR_NOTE), EntryId::fromString(self::NOTE)]);

        Assert::assertNull($types->of(EntryId::fromString(self::MISSING)));
        Assert::assertNull($types->of(EntryId::fromString(self::FAR_NOTE)));
        Assert::assertEquals(TypeId::fromString(self::NOTE_TYPE), $types->of(EntryId::fromString(self::NOTE)));
    }

    #[Test]
    public function it_gives_nothing_for_no_entries(): void
    {
        Assert::assertNull($this->relationTargets()->types([])->of(EntryId::fromString(self::ARTICLE)));
    }

    #[Test]
    public function it_gives_the_nodes_the_actor_can_read_and_none_that_does_not_exist_or_lies_outside_its_regions(): void
    {
        $nodes = $this->relationTargets()->nodes([NodeId::fromString(self::MISSING_NODE), NodeId::fromString(self::FAR_NODE), NodeId::fromString(self::HOME_NODE)]);

        Assert::assertEquals([NodeId::fromString(self::HOME_NODE)], $nodes);
        Assert::assertSame([], $this->relationTargets()->nodes([]));
    }
}
