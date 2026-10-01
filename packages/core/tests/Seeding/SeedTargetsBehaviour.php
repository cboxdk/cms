<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SeedTargets does, the fake and the Postgres one alike: the nodes the context's regions
 * reach that are not mounts, sorted by id, and the entries of a list that exist and whose home the
 * context's regions reach, in the list's order.
 */
trait SeedTargetsBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000048a1';

    public const string FIRST = '0192a0c0-0000-7000-8000-0000000048a3';

    public const string SECOND = '0192a0c0-0000-7000-8000-0000000048a2';

    public const string MOUNT = '0192a0c0-0000-7000-8000-0000000048a4';

    public const string DEEP = '0192a0c0-0000-7000-8000-0000000048a5';

    public const string OUTSIDE = '0192a0c0-0000-7000-8000-0000000048a6';

    public const string IN_FIRST = '0192a0c0-0000-7000-8000-0000000048b1';

    public const string IN_DEEP = '0192a0c0-0000-7000-8000-0000000048b2';

    public const string IN_OUTSIDE = '0192a0c0-0000-7000-8000-0000000048b3';

    public const string UNKNOWN = '0192a0c0-0000-7000-8000-0000000048b4';

    /**
     * The targets under test, knowing the site root ROOT with FIRST, SECOND and the mount MOUNT
     * (of FIRST) below it, DEEP below SECOND, and the root OUTSIDE of another tree, and the entries
     * IN_FIRST homed on FIRST, IN_DEEP on DEEP and IN_OUTSIDE on OUTSIDE, owned by no actor.
     */
    abstract protected function seedTargets(): SeedTargets;

    /**
     * The actor the contexts of the tests name.
     */
    abstract protected function actor(): ActorId;

    #[Test]
    public function it_gives_every_node_the_regions_reach_but_mounts_sorted_by_id(): void
    {
        $nodes = $this->seedTargets()->nodes($this->access(new AccessRegion(new NodePath($this->label(self::ROOT)))));

        Assert::assertSame([self::ROOT, self::SECOND, self::FIRST, self::DEEP], array_map(static fn (NodeId $node): string => $node->toString(), $nodes));
    }

    #[Test]
    public function it_leaves_out_what_a_region_s_exception_or_no_region_leaves_out(): void
    {
        $root = new NodePath($this->label(self::ROOT));
        $second = new NodePath($this->label(self::ROOT).'.'.$this->label(self::SECOND));

        Assert::assertSame(
            [self::ROOT, self::FIRST],
            array_map(static fn (NodeId $node): string => $node->toString(), $this->seedTargets()->nodes($this->access(new AccessRegion($root, [$second])))),
        );
        Assert::assertSame([], $this->seedTargets()->nodes($this->access()));
    }

    #[Test]
    public function it_gives_the_entries_of_the_list_that_exist_where_the_regions_reach_in_the_list_s_order(): void
    {
        $root = new NodePath($this->label(self::ROOT));
        $second = new NodePath($this->label(self::ROOT).'.'.$this->label(self::SECOND));
        $list = array_map(EntryId::fromString(...), [self::UNKNOWN, self::IN_DEEP, self::IN_OUTSIDE, self::IN_FIRST]);

        Assert::assertSame([self::IN_DEEP, self::IN_FIRST], $this->entryIds($this->seedTargets()->existing($this->access(new AccessRegion($root)), $list)));
        Assert::assertSame([self::IN_FIRST], $this->entryIds($this->seedTargets()->existing($this->access(new AccessRegion($root, [$second])), $list)));
        Assert::assertSame([], $this->seedTargets()->existing($this->access(), $list));
        Assert::assertSame([], $this->seedTargets()->existing($this->access(new AccessRegion($root)), []));
    }

    /**
     * @param  list<EntryId>  $entries
     * @return list<string>
     */
    private function entryIds(array $entries): array
    {
        return array_map(static fn (EntryId $entry): string => $entry->toString(), $entries);
    }

    protected function label(string $id): string
    {
        return str_replace('-', '', $id);
    }

    private function access(AccessRegion ...$regions): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal($this->actor(), [], IssuerKind::Service, ClassificationAccess::Sensitive),
            array_values($regions),
            ClassificationAccess::Internal,
        );
    }
}
