<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What node.create read (PRD 6.2 phase 1): the node with the command's id, which it expects not to
 * exist, and the parent it goes below, null when no node has that id. The kernel checks at commit
 * that the node is still absent and the parent still at the version read, so a node created with
 * the same id, or a parent archived or moved meanwhile, is version_conflict.
 */
#[Internal]
final readonly class CreateNodeAggregates implements Aggregates
{
    public function __construct(
        public NodeId $node,
        public ?StoredNode $current,
        public NodeId $parent,
        public ?StoredNode $storedParent,
    ) {}

    /**
     * The node and its parent, each once: a command that names one node as both is one read, which
     * the command expects absent, so creating a node below itself is version_conflict when it
     * exists and validation_failed when it does not.
     */
    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [new ReadVersion($this->node, $this->current?->version)];

        if (! $this->parent->equals($this->node)) {
            $reads[] = new ReadVersion($this->parent, $this->storedParent?->version);
        }

        return new ReadVersions(...$reads);
    }

    /**
     * The parent, in every locale: a node below it is created with the rights on it (PRD 5.10).
     * Anywhere when the parent read as absent or outside the actor's regions, which the refusals
     * answer with their own codes.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->storedParent instanceof StoredNode && $this->storedParent->reachable
            ? AuthorizationScope::on(new AuthorizationTarget($this->parent))
            : AuthorizationScope::anywhere();
    }
}
