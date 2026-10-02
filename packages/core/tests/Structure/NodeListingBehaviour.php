<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Structure\Domain\NodeListing;
use Cbox\Cms\Core\Tests\Access\ListingWorld;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every NodeListing of the kernel does (PRD 5.8, 5.10), held against the fake the action
 * tests use and PostgresNodeListing, over the ListingWorld: the nodes the context's regions reach,
 * in tree order, each with its parent, kind, site and path label, after the node given; none to an
 * actor whose regions reach nothing.
 */
trait NodeListingBehaviour
{
    /**
     * Gives NodeListing read under the context of the reader, an actor of ListingWorld::READERS.
     *
     * @param  Closure(NodeListing): void  $read
     */
    abstract protected function listAs(string $reader, Closure $read): void;

    #[Test]
    public function it_lists_every_node_the_context_reaches_in_tree_order_with_its_path_label(): void
    {
        $this->listAs(ListingWorld::ADMIN, static function (NodeListing $listing): void {
            [$root, $news, $sport, $culture] = ListingWorld::nodes();

            Assert::assertEquals([$root, $news, $sport, $culture], $listing->reached(null, 10));
            Assert::assertSame(['north', 'north/nyheder', 'north/nyheder/section', 'north/list'], [$root->label, $news->label, $sport->label, $culture->label]);
            Assert::assertEquals([$sport], $listing->reached(NodeId::fromString(ListingWorld::NEWS), 1));
            Assert::assertSame([], $listing->reached(NodeId::fromString(ListingWorld::CULTURE), 10));
        });
    }

    #[Test]
    public function it_lists_only_the_nodes_the_context_reaches(): void
    {
        $this->listAs(ListingWorld::EDITOR, static function (NodeListing $listing): void {
            [, $news] = ListingWorld::nodes();

            Assert::assertEquals([$news], $listing->reached(null, 10));
            Assert::assertSame([], $listing->reached(NodeId::fromString(ListingWorld::NEWS), 10));
            Assert::assertEquals([$news], $listing->reached(NodeId::fromString(ListingWorld::ROOT), 10));
        });

        $this->listAs(ListingWorld::SERVICE, static function (NodeListing $listing): void {
            Assert::assertSame([], $listing->reached(null, 10));
        });
    }
}
