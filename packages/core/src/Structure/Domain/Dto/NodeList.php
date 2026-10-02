<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of node.list: a page of nodes in tree order, and the id to read the next page after,
 * or null when this page is the last.
 */
#[Experimental]
final readonly class NodeList implements Result
{
    /**
     * @param  list<ListedNode>  $nodes
     */
    public function __construct(
        public array $nodes,
        public ?NodeId $next,
    ) {}
}
