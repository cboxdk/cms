<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Dto\MountedEntry;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredMountOverride;
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
 * with the route `/` to ROOT and `/nyheder` to NODE in da, a placement LIVE of the entry ENTRY
 * below NODE live until UNTIL, a hidden placement HIDDEN of ENTRY below ARCHIVED, and the
 * exception on MOUNT that hides ENTRY, set at version OVERRIDE_VERSION of the mount.
 *
 * A node outside the regions is read, so a command on it is unauthorized and not a conflict with an
 * aggregate that looks absent; a route is read whoever holds it; a placement below the node is
 * read past the regions, so archiving never hides content the reader cannot see; the ancestors of
 * a node are read from the root down, so a move locks the path it lands under; the routed node
 * nearest the top of a subtree is read, so a move to another site weighs the routes below it; a
 * mount's exception for an entry is read as every context reads it; and what a node shows of an
 * entry is read past the regions, so un-hiding on a mount weighs what the source shows.
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

    public const string ENTRY = '0192a0c0-0000-7000-8000-00000000b030';

    public const string NO_ENTRY = '0192a0c0-0000-7000-8000-00000000b031';

    /** The version of MOUNT at which its exception for ENTRY was set. */
    public const int OVERRIDE_VERSION = 2;

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
        Assert::assertSame(self::NODE, $mount->mountSource?->toString());
        Assert::assertNull($archived->mountSource);
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
    public function it_reads_the_ancestors_of_a_node_from_the_root_down_and_none_for_a_root_or_a_node_that_does_not_exist(): void
    {
        $reader = $this->nodeReader();
        $ancestors = $reader->ancestors(NodeId::fromString(self::NODE));

        Assert::assertSame([self::ROOT], array_map(static fn (StoredNode $ancestor): string => $ancestor->id->toString(), $ancestors));
        Assert::assertSame([1, true, 'site'], [$ancestors[0]->version->value, $ancestors[0]->reachable, $ancestors[0]->kind->value]);
        Assert::assertSame([], $reader->ancestors(NodeId::fromString(self::ROOT)));
        Assert::assertSame([], $reader->ancestors(NodeId::fromString(self::NOWHERE)));
    }

    #[Test]
    public function it_reads_the_routed_node_nearest_the_top_below_a_node_and_none_below_an_unrouted_one(): void
    {
        $reader = $this->nodeReader();

        Assert::assertSame(self::ROOT, $reader->routedBelow(NodeId::fromString(self::ROOT))?->toString());
        Assert::assertSame(self::NODE, $reader->routedBelow(NodeId::fromString(self::NODE))?->toString());
        Assert::assertNull($reader->routedBelow(NodeId::fromString(self::ARCHIVED)));
        Assert::assertNull($reader->routedBelow(NodeId::fromString(self::MOUNT)));
        Assert::assertNull($reader->routedBelow(NodeId::fromString(self::NOWHERE)));
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

    #[Test]
    public function it_reads_the_exception_a_mount_has_for_an_entry_and_none_for_another_entry_or_node(): void
    {
        $reader = $this->nodeReader();
        $override = $reader->mountOverride(NodeId::fromString(self::MOUNT), EntryId::fromString(self::ENTRY));

        Assert::assertInstanceOf(StoredMountOverride::class, $override);
        Assert::assertSame(
            [self::MOUNT, self::ENTRY, true, self::OVERRIDE_VERSION],
            [$override->mount->toString(), $override->entry->toString(), $override->hidden, $override->version->value],
        );
        Assert::assertNull($reader->mountOverride(NodeId::fromString(self::MOUNT), EntryId::fromString(self::NO_ENTRY)));
        Assert::assertNull($reader->mountOverride(NodeId::fromString(self::NODE), EntryId::fromString(self::ENTRY)));
    }

    #[Test]
    public function it_reads_what_a_node_shows_of_an_entry_past_the_regions_and_nothing_for_an_entry_that_does_not_exist(): void
    {
        $reader = $this->nodeReader();
        $entry = EntryId::fromString(self::ENTRY);
        $now = new DateTimeImmutable(self::NOW);
        $shown = $reader->mountedEntry(NodeId::fromString(self::ROOT), $entry, $now);
        $unshown = $reader->mountedEntry(NodeId::fromString(self::ARCHIVED), $entry, $now);
        $ended = $reader->mountedEntry(NodeId::fromString(self::NODE), $entry, new DateTimeImmutable(self::UNTIL));

        Assert::assertInstanceOf(MountedEntry::class, $shown);
        Assert::assertSame([self::ENTRY, self::LIVE], [$shown->entry->toString(), $shown->visible?->toString()]);
        Assert::assertInstanceOf(MountedEntry::class, $unshown);
        Assert::assertNull($unshown->visible);
        Assert::assertInstanceOf(MountedEntry::class, $ended);
        Assert::assertNull($ended->visible);
        Assert::assertNull($reader->mountedEntry(NodeId::fromString(self::ROOT), EntryId::fromString(self::NO_ENTRY), $now));
    }
}
