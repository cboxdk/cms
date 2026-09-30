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
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every SeedTargets does, the fake and the Postgres one alike: the nodes the context's regions
 * reach that are not mounts, sorted by id.
 */
trait SeedTargetsBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-0000000048a1';

    public const string FIRST = '0192a0c0-0000-7000-8000-0000000048a3';

    public const string SECOND = '0192a0c0-0000-7000-8000-0000000048a2';

    public const string MOUNT = '0192a0c0-0000-7000-8000-0000000048a4';

    public const string DEEP = '0192a0c0-0000-7000-8000-0000000048a5';

    public const string OUTSIDE = '0192a0c0-0000-7000-8000-0000000048a6';

    /**
     * The targets under test, knowing the site root ROOT with FIRST, SECOND and the mount MOUNT
     * (of FIRST) below it, DEEP below SECOND, and the root OUTSIDE of another tree.
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
