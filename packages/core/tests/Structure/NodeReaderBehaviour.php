<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every NodeReader of the kernel does (PRD 5.8, 5.9, 5.10), held against the fake the node
 * action tests use and PostgresNodeReader, over one world the implementation's test writes: the
 * site root ROOT with the section NODE, the archived section ARCHIVED and the mount MOUNT below it,
 * the root FAR of another site outside the reader's regions, the site SITE publishing in da and en
 * with the route `/` to ROOT and `/nyheder` to NODE in da, a placement LIVE below NODE live until
 * UNTIL, and a hidden placement HIDDEN below ARCHIVED.
 *
 * A node outside the regions is read, so a command on it is unauthorized and not a conflict with an
 * aggregate that looks absent; a route is read whoever holds it; and a placement below the node is
 * read past the regions, so archiving never hides content the reader cannot see.
 */
trait NodeReaderBehaviour
{
    public const string ROOT = '0192a0c0-0000-7000-8000-00000000b001';

    public const string NODE = '0192a0c0-0000-7000-8000-00000000b002';

    public const string ARCHIVED = '0192a0c0-0000-7000-8000-00000000b003';

    public const string MOUNT = '0192a0c0-0000-7000-8000-00000000b004';

    public const string FAR = '0192a0c0-0000-7000-8000-00000000b005';

    public const string NOWHERE = '0192a0c0-0000-7000-8000-00000000b006';

    public const string SITE = '0192a0c0-0000-7000-8000-00000000b010';

    public const string LIVE = '0192a0c0-0000-7000-8000-00000000b020';

    public const string HIDDEN = '0192a0c0-0000-7000-8000-00000000b021';

    /** The version NODE is written at, so a read gives the version the commit checks. */
    public const int NODE_VERSION = 3;

    /** When the live placement's window ends. */
    public const string UNTIL = '2026-04-01T00:00:00Z';

    /** A time inside the live placement's window. */
    public const string NOW = '2026-03-10T12:00:00Z';

    abstract protected function nodeReader(): NodeReader;

    /**
     * The ltree label of a node: its id without the hyphens.
     */
    protected static function label(string $id): string
    {
        return str_replace('-', '', $id);
    }

    #[Test]
    public function it_reads_a_node_with_its_parent_kind_path_lifecycle_and_version(): void
    {
        $reader = $this->nodeReader();
        $root = $reader->node(NodeId::fromString(self::ROOT));
        $node = $reader->node(NodeId::fromString(self::NODE));

        Assert::assertInstanceOf(StoredNode::class, $root);
        Assert::assertInstanceOf(StoredNode::class, $node);
        Assert::assertSame(
            [null, 'site', self::label(self::ROOT), 'active', 1, true],
            [$root->parent, $root->kind->value, $root->path->value, $root->lifecycle->value, $root->version->value, $root->reachable],
        );
        Assert::assertSame(
            [self::NODE, self::ROOT, 'section', self::label(self::ROOT).'.'.self::label(self::NODE), 'active', self::NODE_VERSION, true],
            [$node->id->toString(), $node->parent?->toString(), $node->kind->value, $node->path->value, $node->lifecycle->value, $node->version->value, $node->reachable],
        );
    }

    #[Test]
    public function it_reads_an_archived_node_a_mount_and_nothing_for_a_node_that_does_not_exist(): void
    {
        $reader = $this->nodeReader();
        $archived = $reader->node(NodeId::fromString(self::ARCHIVED));
        $mount = $reader->node(NodeId::fromString(self::MOUNT));

        Assert::assertSame(NodeLifecycle::Archived, $archived?->lifecycle);
        Assert::assertTrue($archived->archived());
        Assert::assertSame(NodeKind::Mount, $mount?->kind);
        Assert::assertFalse($mount->archived());
        Assert::assertNull($reader->node(NodeId::fromString(self::NOWHERE)));
    }

    #[Test]
    public function it_reads_a_node_outside_the_regions_as_unreachable(): void
    {
        $far = $this->nodeReader()->node(NodeId::fromString(self::FAR));

        Assert::assertInstanceOf(StoredNode::class, $far);
        Assert::assertFalse($far->reachable);
        Assert::assertSame('site', $far->kind->value);
    }

    #[Test]
    public function it_reads_the_node_that_holds_a_route_and_nothing_for_a_free_one(): void
    {
        $reader = $this->nodeReader();
        $site = SiteId::fromString(self::SITE);
        $da = new Locale('da');

        Assert::assertSame(self::NODE, $reader->routeHolder($site, $da, new RequestPath('/nyheder'))?->toString());
        Assert::assertSame(self::ROOT, $reader->routeHolder($site, $da, new RequestPath('/'))?->toString());
        Assert::assertNull($reader->routeHolder($site, $da, new RequestPath('/sport')));
        Assert::assertNull($reader->routeHolder($site, new Locale('en'), new RequestPath('/nyheder')));
    }

    #[Test]
    public function it_reads_the_route_a_node_has_on_a_site_in_a_language(): void
    {
        $reader = $this->nodeReader();
        $site = SiteId::fromString(self::SITE);
        $da = new Locale('da');

        Assert::assertSame('/nyheder', $reader->routeOf($site, $da, NodeId::fromString(self::NODE))?->value);
        Assert::assertNull($reader->routeOf($site, new Locale('en'), NodeId::fromString(self::NODE)));
        Assert::assertNull($reader->routeOf($site, $da, NodeId::fromString(self::MOUNT)));
    }

    #[Test]
    public function it_reads_a_placement_below_the_node_that_is_visible_now_or_later_and_none_after_its_window(): void
    {
        $reader = $this->nodeReader();
        $now = new DateTimeImmutable(self::NOW);

        Assert::assertSame(self::LIVE, $reader->visiblePlacement(NodeId::fromString(self::ROOT), $now)?->toString());
        Assert::assertSame(self::LIVE, $reader->visiblePlacement(NodeId::fromString(self::NODE), $now)?->toString());
        Assert::assertNull($reader->visiblePlacement(NodeId::fromString(self::ARCHIVED), $now));
        Assert::assertNull($reader->visiblePlacement(NodeId::fromString(self::ROOT), new DateTimeImmutable(self::UNTIL)));
        Assert::assertInstanceOf(PlacementId::class, $reader->visiblePlacement(NodeId::fromString(self::ROOT), $now));
    }
}
