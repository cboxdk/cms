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
 * What node.move read (PRD 6.2 phase 1), at the time it read: the node, null when no node has the
 * id; the parent it goes below, null when none has the id; the parent it leaves, null when the node
 * is a root or absent; every ancestor of the new parent, the root first; and, when the move lands
 * under another site's root, a node below the moved node that has a route, and a placement below it
 * that is visible then or later, each null when there is none.
 *
 * Every node read is an aggregate the kernel checks at commit, each once, however many times the
 * command names it: a parent or an ancestor changed meanwhile is version_conflict, which is what
 * keeps two moves that would make a cycle together from both committing. The new parent is read
 * once when the command names the node as its own parent.
 */
#[Internal]
final readonly class MoveNodeAggregates implements Aggregates
{
    /**
     * @param  list<StoredNode>  $ancestors  of the new parent, the root first
     */
    public function __construct(
        public NodeId $node,
        public ?StoredNode $current,
        public NodeId $parent,
        public ?StoredNode $newParent,
        public ?StoredNode $oldParent,
        public array $ancestors,
        public ?NodeId $routed,
        public ?PlacementId $visible,
        public DateTimeImmutable $at,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [
            $this->node->aggregateKey() => new ReadVersion($this->node, $this->current?->version),
            $this->parent->aggregateKey() => new ReadVersion($this->parent, $this->newParent?->version),
        ];

        if ($this->oldParent instanceof StoredNode) {
            $reads[$this->oldParent->id->aggregateKey()] ??= new ReadVersion($this->oldParent->id, $this->oldParent->version);
        }

        foreach ($this->ancestors as $ancestor) {
            $reads[$ancestor->id->aggregateKey()] ??= new ReadVersion($ancestor->id, $ancestor->version);
        }

        return new ReadVersions(...array_values($reads));
    }

    /**
     * Whether the move takes the node under another site's root: the root of the tree the new
     * parent is in is not the root of the tree the node is in.
     */
    public function crossesSites(): bool
    {
        return $this->current instanceof StoredNode
            && $this->newParent instanceof StoredNode
            && $this->current->rootLabel() !== $this->newParent->rootLabel();
    }

    /**
     * Both parents, in every locale (PRD 5.10): the node is taken from the one and put below the
     * other. Anywhere when one of them read as absent or outside the actor's regions.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        if (
            ! $this->current instanceof StoredNode
            || ! $this->current->reachable
            || ! $this->newParent instanceof StoredNode
            || ! $this->newParent->reachable
            || ! $this->oldParent instanceof StoredNode
            || ! $this->oldParent->reachable
        ) {
            return AuthorizationScope::anywhere();
        }

        return $this->oldParent->id->equals($this->newParent->id)
            ? AuthorizationScope::on(new AuthorizationTarget($this->oldParent->id))
            : AuthorizationScope::on(new AuthorizationTarget($this->oldParent->id), new AuthorizationTarget($this->newParent->id));
    }
}
