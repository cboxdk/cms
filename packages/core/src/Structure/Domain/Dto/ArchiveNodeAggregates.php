<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use DateTimeImmutable;
use Override;

/**
 * What node.archive read (PRD 6.2 phase 1), at the time it read: the node, null when no node has
 * the id, and a placement below it that is visible then or later, null when none is. The kernel
 * checks at commit that the node is still at the version read, so a node changed meanwhile is
 * version_conflict; the writer reads the placements again in the commit, because a window opened on
 * one of them does not change the node's version.
 */
#[Internal]
final readonly class ArchiveNodeAggregates implements Aggregates
{
    public function __construct(
        public NodeId $node,
        public ?StoredNode $current,
        public ?PlacementId $visible,
        public DateTimeImmutable $at,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(new ReadVersion($this->node, $this->current?->version));
    }

    /**
     * The node itself, in every locale (PRD 5.10); anywhere when it read as absent or outside the
     * actor's regions.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->current instanceof StoredNode && $this->current->reachable
            ? AuthorizationScope::on(new AuthorizationTarget($this->node))
            : AuthorizationScope::anywhere();
    }
}
