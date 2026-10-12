<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What node.set_label read (PRD 6.2 phase 1): the node, null when no node has the id. The kernel
 * checks at commit that the node is still at the version read, so a node changed meanwhile is
 * version_conflict.
 */
#[Internal]
final readonly class SetNodeLabelAggregates implements Aggregates
{
    public function __construct(
        public NodeId $node,
        public ?StoredNode $current,
        public Locale $locale,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(new ReadVersion($this->node, $this->current?->version));
    }

    /**
     * The node, in the label's locale (PRD 5.10); anywhere when it read as absent or outside the
     * actor's regions.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->current instanceof StoredNode && $this->current->reachable
            ? AuthorizationScope::on(new AuthorizationTarget($this->node, $this->locale))
            : AuthorizationScope::anywhere();
    }
}
