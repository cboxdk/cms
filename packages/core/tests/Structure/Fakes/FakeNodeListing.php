<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure\Fakes;

use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Structure\Domain\Dto\ListedNode;
use Cbox\Cms\Core\Structure\Domain\NodeListing;
use Override;

/**
 * NodeListing in memory (NodeListingBehaviour holds it to PostgresNodeListing): a test adds the
 * nodes in tree order, each with whether the context's regions reach it, and reached() gives the
 * reached ones after the node given, nothing after a node it does not know.
 */
final class FakeNodeListing implements NodeListing
{
    /** @var list<array{ListedNode, bool}> in tree order, with whether the context reaches it */
    private array $nodes = [];

    public function add(ListedNode $node, bool $reached = true): self
    {
        $this->nodes[] = [$node, $reached];

        return $this;
    }

    #[Override]
    public function reached(?NodeId $after, int $limit): array
    {
        $listed = [];
        $started = ! $after instanceof NodeId;

        foreach ($this->nodes as [$node, $reached]) {
            if (! $started) {
                $started = $after instanceof NodeId && $node->id->equals($after);

                continue;
            }

            if ($reached) {
                $listed[] = $node;
            }
        }

        return array_slice($listed, 0, $limit);
    }
}
